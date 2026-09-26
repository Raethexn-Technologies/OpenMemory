<?php

namespace App\Services\Context;

use App\Models\ContextApplication;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ContextApplications
{
    public function __construct(private readonly ContextAudit $audit) {}

    public function create(User $owner, array $input): array
    {
        ContextInput::validate($input, [
            'name' => 'required|string|max:80',
            'capabilities' => 'present|array|list|max:8',
            'capabilities.*' => 'string|distinct|in:'.implode(',', ContextPolicy::CAPABILITIES),
            'expires_in_days' => 'sometimes|integer|min:1|max:365',
            'source_resources' => 'sometimes|array|list|max:20',
            'source_resources.*' => 'uuid|distinct',
            ...self::modelRules(),
        ]);
        $this->validateResources($owner, $input['source_resources'] ?? []);
        $this->validateModel($input);

        return DB::transaction(function () use ($owner, $input) {
            $token = 'omctx_'.bin2hex(random_bytes(32));
            $app = new ContextApplication([
                'name' => $input['name'], 'token_hash' => hash('sha256', $token),
                'capabilities' => $input['capabilities'], 'grant_revision' => 1,
                'source_resources' => $input['source_resources'] ?? [],
                'model_disclosure' => $input['model_disclosure'] ?? null,
                'expires_at' => now()->addDays((int) ($input['expires_in_days'] ?? 30)),
            ]);
            $app->owner_id = $owner->id;
            $app->save();
            $this->audit->record(new ContextCaller($owner, $app->id), (string) Str::uuid(), 'application.create', 'allowed');

            return ['application' => $app->summary(), 'token' => $token];
        });
    }

    public function change(User $owner, string $id, array $input): array
    {
        ContextInput::validate($input, [
            'grant_revision' => 'required|integer|min:1|max:2147483646',
            'capabilities' => 'present|array|list|max:8',
            'capabilities.*' => 'string|distinct|in:'.implode(',', ContextPolicy::CAPABILITIES),
            'source_resources' => 'sometimes|array|list|max:20',
            'source_resources.*' => 'uuid|distinct',
            ...self::modelRules(),
        ]);
        $this->validateResources($owner, $input['source_resources'] ?? []);
        $this->validateModel($input);

        return DB::transaction(function () use ($owner, $id, $input) {
            $app = ContextApplication::where('owner_id', $owner->id)->whereKey($id)->lockForUpdate()->firstOrFail();
            abort_if($app->revoked_at !== null || $app->grant_revision !== (int) $input['grant_revision'], 409);
            $app->capabilities = $input['capabilities'];
            $app->source_resources = $input['source_resources'] ?? [];
            $app->model_disclosure = $input['model_disclosure'] ?? null;
            $app->grant_revision++;
            $app->save();
            $this->audit->record(new ContextCaller($owner, $id), (string) Str::uuid(), 'application.grants', 'allowed');

            return $app->summary();
        });
    }

    public function revoke(User $owner, string $id): void
    {
        DB::transaction(function () use ($owner, $id) {
            $app = ContextApplication::where('owner_id', $owner->id)->whereKey($id)->lockForUpdate()->firstOrFail();
            $app->revoked_at = now();
            $app->save();
            $this->audit->record(new ContextCaller($owner, $id), (string) Str::uuid(), 'application.revoke', 'allowed');
        });
    }

    private function validateResources(User $owner, array $ids): void
    {
        $count = \App\Models\SourceResource::whereIn('id', $ids)->where('selected', true)
            ->whereHas('connection', fn ($q) => $q->where('owner_id', $owner->id)->whereNull('disconnected_at'))->count();
        if ($count !== count($ids)) {
            ContextInput::validate(['invalid_resource_grant' => true], []);
        }
    }

    private static function modelRules(): array
    {
        return [
            'model_disclosure' => 'sometimes|nullable|array:destination,model,sources|required_array_keys:destination,model,sources',
            'model_disclosure.destination' => ['required_with:model_disclosure', 'string', 'max:200', 'regex:~^https://[a-z0-9]+(?:[.-][a-z0-9]+)*$~D'],
            'model_disclosure.model' => ['required_with:model_disclosure', 'string', 'max:120', 'regex:~^[A-Za-z0-9][A-Za-z0-9._:/-]*$~D'],
            'model_disclosure.sources' => 'required_with:model_disclosure|array|list|min:1|max:3',
            'model_disclosure.sources.*' => 'distinct|in:'.implode(',', array_keys(ContextSources::DEFINITIONS)),
        ];
    }

    private function validateModel(array $input): void
    {
        foreach ($input['model_disclosure']['sources'] ?? [] as $source) {
            if (array_diff(['context.resolve', ContextSources::DEFINITIONS[$source]['read'], ContextSources::DEFINITIONS[$source]['disclose']], $input['capabilities']) !== []) {
                ContextInput::validate(['model_source_not_granted' => true], []);
            }
        }
    }
}
