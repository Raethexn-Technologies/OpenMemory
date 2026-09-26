<?php

namespace App\Services\GitHub;

use App\Models\SourceConnection;
use Illuminate\Support\Facades\Http;
use Throwable;

class GitHubClient
{
    public const MAX_BYTES = 524288;

    public function get(#[\SensitiveParameter] string $token, string $path, array $query = []): array
    {
        // Call sites choose only fixed API operations; no caller URL is accepted.
        if (! preg_match('~^/(user|user/repos|repos/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+(?:/commits)?)$~D', $path)) {
            throw new GitHubFailure('provider_response_invalid');
        }
        try {
            $deadline = microtime(true) + 5;
            app(\App\Services\Context\ContextMetrics::class)->providerRequest('github');
            $response = Http::withToken($token)->accept('application/vnd.github+json')
                ->withHeaders(['X-GitHub-Api-Version' => '2026-03-10', 'User-Agent' => 'OpenMemory'])
                ->connectTimeout(2)->timeout(5)->withOptions([
                    'allow_redirects' => false, 'stream' => true, 'read_timeout' => 1,
                    'on_headers' => function ($response) {
                        if ((int) $response->getHeaderLine('Content-Length') > self::MAX_BYTES) {
                            throw new GitHubFailure('response_too_large');
                        }
                    },
                ])->get('https://api.github.com'.$path, $query);
            $body = $response->toPsrResponse()->getBody();
            try {
                $raw = '';
                while (! $body->eof() && strlen($raw) <= self::MAX_BYTES) {
                    if (microtime(true) > $deadline) {
                        throw new GitHubFailure('provider_timeout');
                    }
                    $raw .= $body->read(min(8192, self::MAX_BYTES + 1 - strlen($raw)));
                }
                if (strlen($raw) > self::MAX_BYTES) {
                    throw new GitHubFailure('response_too_large');
                }
            } finally {
                $body->close();
            }
            $status = $response->status();
            $remaining = $response->header('X-RateLimit-Remaining');
            $retry = $response->header('Retry-After');
            $reset = $response->header('X-RateLimit-Reset');
            $limited = $status === 429 || ($status === 403 && ($remaining === '0' || $retry !== ''
                || str_contains(strtolower($raw), 'rate limit')));
            if ($limited) {
                $at = max(time() + 60, ctype_digit($retry) ? time() + (int) $retry : (strtotime($retry) ?: 0),
                    ctype_digit($reset) ? (int) $reset : 0);
                throw new GitHubFailure('rate_limited', min($at, 4102444800));
            }
            if ($status !== 200) {
                throw new GitHubFailure(match ($status) {
                    401 => 'credential_invalid', 403 => 'repository_access_denied',
                    404, 410 => 'repository_inaccessible', 409 => 'repository_empty',
                    301, 302, 307, 308 => 'repository_moved',
                    default => 'source_unavailable',
                });
            }
            try {
                $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new GitHubFailure('provider_response_invalid');
            }
            if (! is_array($data)) {
                throw new GitHubFailure('provider_response_invalid');
            }

            return ['data' => $data, 'next' => str_contains($response->header('Link'), 'rel="next"'),
                'retry_at' => $remaining === '0' && ctype_digit($reset) ? (int) $reset : null];
        } catch (GitHubFailure $failure) {
            throw $failure;
        } catch (Throwable $failure) {
            // Wrapped transport exceptions can contain tokens, URLs, and bodies.
            for ($cause = $failure; $cause !== null; $cause = $cause->getPrevious()) {
                if ($cause instanceof GitHubFailure) {
                    throw new GitHubFailure($cause->outcome, $cause->retryAt);
                }
                if ($cause instanceof \GuzzleHttp\Exception\ConnectException
                    && ($cause->getHandlerContext()['errno'] ?? null) === 28) {
                    throw new GitHubFailure('provider_timeout');
                }
            }
            throw new GitHubFailure('source_unavailable');
        }
    }

    public function connected(SourceConnection $snapshot, string $path, array $query = [], ?callable $beforeRequest = null): array
    {
        $connection = SourceConnection::find($snapshot->id);
        if (! $connection || $connection->disconnected_at !== null) {
            throw new GitHubFailure('source_disconnected');
        }
        if ($connection->revision !== $snapshot->revision) {
            throw new GitHubFailure('authorization_changed');
        }
        if ($connection->credential_expires_at->isPast()) {
            throw new GitHubFailure('credential_expired');
        }
        if ($connection->retry_at?->isFuture()) {
            throw new GitHubFailure('rate_limited', $connection->retry_at->timestamp);
        }
        if ($connection->credential === null) {
            throw new GitHubFailure('credential_invalid');
        }
        try {
            // Persist the disclosure intent before making an external request.
            if ($beforeRequest !== null) {
                $beforeRequest();
            }
            $result = $this->get($connection->credential, $path, $query);
            if ($result['retry_at'] !== null) {
                SourceConnection::whereKey($connection->id)->where('revision', $connection->revision)
                    ->update(['retry_at' => gmdate('Y-m-d H:i:s', min($result['retry_at'], 4102444800))]);
            }

            return $result;
        } catch (GitHubFailure $failure) {
            if ($failure->retryAt !== null) {
                SourceConnection::whereKey($connection->id)->where('revision', $connection->revision)
                    ->update(['retry_at' => gmdate('Y-m-d H:i:s', $failure->retryAt)]);
            }
            if ($failure->outcome === 'credential_invalid') {
                SourceConnection::whereKey($connection->id)->where('revision', $connection->revision)->update(['credential' => null]);
            }
            throw $failure;
        }
    }

    public static function repository(array $data): array
    {
        if (! isset($data['id'], $data['full_name']) || ! ctype_digit((string) $data['id'])
            || strlen((string) $data['id']) > 32 || ! is_string($data['full_name'])
            || strlen($data['full_name']) > 200
            || ! preg_match('~^[A-Za-z0-9_-]+/[A-Za-z0-9_.-]+$~D', $data['full_name'])
            || in_array(explode('/', $data['full_name'])[1], ['.', '..'], true)) {
            throw new GitHubFailure('provider_response_invalid');
        }

        return ['external_id' => (string) $data['id'], 'reference' => $data['full_name']];
    }
}
