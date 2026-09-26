<?php

namespace App\Services\GitHub;

use App\Models\SourceConnection;
use App\Models\SourceResource;
use App\Models\User;
use App\Services\Context\ContextAudit;
use App\Services\Context\ContextCaller;
use App\Services\Context\ContextInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GitHubConnections
{
    public function __construct(private readonly GitHubClient $client, private readonly ContextAudit $audit) {}

    public function connect(User $owner, #[\SensitiveParameter] array $input): SourceConnection
    {
        ContextInput::validate($input, [
            'token' => ['required', 'string', 'max:512', 'regex:/^github_pat_[A-Za-z0-9_]+$/D'],
            'expires_at' => 'required|date_format:Y-m-d\TH:i:s\Z|after:now|before:+366 days',
        ]);
        abort_if(SourceConnection::where('owner_id', $owner->id)->where('provider', 'github')->whereNull('disconnected_at')->exists(), 409);
        $identity = $this->client->get($input['token'], '/user')['data'];
        if (! isset($identity['id'], $identity['login']) || ! ctype_digit((string) $identity['id'])
            || strlen((string) $identity['id']) > 32 || ! is_string($identity['login'])
            || ! preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $identity['login'])) {
            throw new GitHubFailure('provider_response_invalid');
        }

        return DB::transaction(function () use ($owner, $input, $identity) {
            $connection = SourceConnection::where('owner_id', $owner->id)->where('provider', 'github')->lockForUpdate()->first();
            abort_if($connection && $connection->disconnected_at === null, 409);
            if ($connection) {
                // New local resource UUIDs require new application grants after reconnect.
                SourceResource::where('connection_id', $connection->id)->delete();
            } else {
                $connection = new SourceConnection;
                $connection->owner_id = $owner->id;
            }
            $connection->fill([
                'provider' => 'github', 'external_account_id' => (string) $identity['id'],
                'external_account_login' => $identity['login'], 'credential' => $input['token'],
                'credential_expires_at' => $input['expires_at'], 'disconnected_at' => null,
                'retry_at' => null, 'query_disclosures' => [], 'revision' => ($connection->revision ?? 0) + 1,
            ])->save();
            $this->record($owner, 'source.connect');

            return $connection;
        });
    }

    public function find(User $owner, string $id): SourceConnection
    {
        return SourceConnection::where('owner_id', $owner->id)->where('provider', 'github')->findOrFail($id);
    }

    public function discover(User $owner, string $id, array $input): array
    {
        ContextInput::validate($input, ['page' => 'sometimes|integer|min:1|max:10']);
        $connection = $this->find($owner, $id);
        $page = (int) ($input['page'] ?? 1);
        $result = $this->client->connected($connection, '/user/repos', ['per_page' => 30, 'page' => $page, 'sort' => 'full_name']);
        if (! array_is_list($result['data']) || count($result['data']) > 30) {
            throw new GitHubFailure('provider_response_invalid');
        }
        $current = $this->find($owner, $id);
        if ($current->revision !== $connection->revision || $current->disconnected_at !== null) {
            throw new GitHubFailure('authorization_changed');
        }
        $this->record($owner, 'source.repositories');

        return ['repositories' => array_map(GitHubClient::repository(...), $result['data']),
            'next_page' => $result['next'] && $page < 10 ? $page + 1 : null,
            'truncated' => $result['next'] && $page === 10];
    }

    public function select(User $owner, string $id, array $input): void
    {
        ContextInput::validate($input, [
            'revision' => 'required|integer|min:1',
            'repositories' => 'present|array|list|max:20',
            'repositories.*' => ['string', 'distinct', 'max:200', 'regex:~^[A-Za-z0-9_-]+/[A-Za-z0-9_.-]+$~D'],
            'query_disclosures' => 'present|array|list|max:1',
            'query_disclosures.*' => 'in:history|distinct',
        ]);
        $connection = $this->find($owner, $id);
        abort_if($connection->revision !== (int) $input['revision'] || $connection->disconnected_at !== null, 409);
        $existing = SourceResource::where('connection_id', $id)->where('selected', true)->get()->keyBy('reference');
        $verified = [];
        $deadline = microtime(true) + 25;
        foreach ($input['repositories'] as $reference) {
            if (microtime(true) > $deadline) {
                throw new GitHubFailure('provider_timeout');
            }
            if (in_array(explode('/', $reference)[1], ['.', '..'], true)) {
                ContextInput::validate(['invalid_repository' => true], []);
            }
            // Deselecting or withdrawing consent must work while GitHub is unavailable.
            $verified[] = isset($existing[$reference])
                ? $existing[$reference]->only(['external_id', 'reference'])
                : GitHubClient::repository($this->client->connected($connection, '/repos/'.$reference)['data']);
        }
        DB::transaction(function () use ($owner, $id, $input, $connection, $verified) {
            $current = SourceConnection::where('owner_id', $owner->id)->whereKey($id)->lockForUpdate()->firstOrFail();
            abort_if($current->revision !== $connection->revision || $current->disconnected_at !== null, 409);
            // Removing a selection destroys its UUID, so reselection cannot revive old grants.
            SourceResource::where('connection_id', $id)->whereNotIn('external_id', array_column($verified, 'external_id'))->delete();
            foreach ($verified as $repository) {
                SourceResource::updateOrCreate(['connection_id' => $id, 'external_id' => $repository['external_id']],
                    ['reference' => $repository['reference'], 'selected' => true]);
            }
            $current->query_disclosures = $input['query_disclosures'];
            $current->revision++;
            $current->save();
            $this->record($owner, 'source.selection');
        });
    }

    public function disconnect(User $owner, string $id): void
    {
        DB::transaction(function () use ($owner, $id) {
            $connection = SourceConnection::where('owner_id', $owner->id)->where('provider', 'github')->whereKey($id)->lockForUpdate()->firstOrFail();
            $connection->credential = null;
            $connection->disconnected_at = now();
            $connection->retry_at = null;
            $connection->query_disclosures = [];
            $connection->revision++;
            $connection->save();
            SourceResource::where('connection_id', $id)->delete();
            $this->record($owner, 'source.disconnect');
        });
    }

    private function record(User $owner, string $operation): void
    {
        $this->audit->record(new ContextCaller($owner), (string) Str::uuid(), $operation, 'allowed');
    }
}
