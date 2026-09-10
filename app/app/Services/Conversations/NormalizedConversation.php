<?php

namespace App\Services\Conversations;

use Illuminate\Support\Carbon;

/**
 * One conversation in the provider-neutral model.
 *
 * Adapters produce these; the import service persists them. The rawPayload
 * carries the exact provider JSON for this conversation so the source survives
 * normalization, and rawWarnings carries anything the adapter could not
 * interpret so unsupported records are reported instead of dropped.
 */
final class NormalizedConversation
{
    /**
     * @param  array<int, NormalizedMessage>  $messages
     * @param  array<string, mixed>  $providerMetadata
     * @param  array<int, string>  $warnings
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $providerConversationId,
        public readonly array $messages,
        public readonly ?string $title = null,
        public readonly ?Carbon $createdAt = null,
        public readonly ?Carbon $updatedAt = null,
        public readonly ?string $sourceAccountLabel = null,
        public readonly ?string $workspaceLabel = null,
        public readonly array $providerMetadata = [],
        public readonly ?string $rawPayload = null,
        public readonly array $warnings = [],
    ) {}

    /**
     * Earliest message timestamp, or null when no message carries one.
     */
    public function firstMessageAt(): ?Carbon
    {
        return $this->boundaryTimestamp(true);
    }

    public function lastMessageAt(): ?Carbon
    {
        return $this->boundaryTimestamp(false);
    }

    /**
     * Distinct model identifiers the archive actually named.
     *
     * @return array<int, string>
     */
    public function models(): array
    {
        $models = [];

        foreach ($this->messages as $message) {
            if (is_string($message->modelSlug) && $message->modelSlug !== '') {
                $models[$message->modelSlug] = true;
            }
        }

        ksort($models);

        return array_keys($models);
    }

    /**
     * Hash over the conversation's identity and every message hash.
     *
     * Re-importing an archive compares this against the stored value to decide
     * whether a known conversation genuinely changed. Message order is part of
     * the hash because a re-ordered transcript is a different transcript.
     */
    public function contentHash(): string
    {
        $parts = [
            $this->provider,
            $this->providerConversationId,
            $this->title ?? '',
            $this->createdAt?->toIso8601String() ?? '',
        ];

        foreach ($this->messages as $message) {
            $parts[] = $message->contentHash();
        }

        return hash('sha256', implode("\x1e", $parts));
    }

    private function boundaryTimestamp(bool $earliest): ?Carbon
    {
        $found = null;

        foreach ($this->messages as $message) {
            if ($message->createdAt === null) {
                continue;
            }

            if ($found === null) {
                $found = $message->createdAt;

                continue;
            }

            $replace = $earliest
                ? $message->createdAt->lessThan($found)
                : $message->createdAt->greaterThan($found);

            if ($replace) {
                $found = $message->createdAt;
            }
        }

        return $found;
    }
}
