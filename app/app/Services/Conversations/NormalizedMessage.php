<?php

namespace App\Services\Conversations;

use Illuminate\Support\Carbon;

/**
 * One message in the provider-neutral model.
 *
 * Every field an archive does not supply stays null. A guessed timestamp or an
 * invented role would corrupt exactly the temporal and attributional reasoning
 * the corpus exists to support, so adapters are required to leave gaps as gaps.
 */
final class NormalizedMessage
{
    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    public const ROLE_SYSTEM = 'system';

    public const ROLE_TOOL = 'tool';

    public const ROLE_UNKNOWN = 'unknown';

    public const ROLES = [
        self::ROLE_USER,
        self::ROLE_ASSISTANT,
        self::ROLE_SYSTEM,
        self::ROLE_TOOL,
        self::ROLE_UNKNOWN,
    ];

    /**
     * @param  array<int, array<string, mixed>>  $contentBlocks  Structural summary: type, order, length.
     * @param  array<int, NormalizedAttachment>  $attachments
     * @param  array<string, mixed>  $providerMetadata
     */
    public function __construct(
        public readonly string $providerMessageId,
        public readonly string $role,
        public readonly string $text,
        public readonly ?string $parentProviderMessageId = null,
        public readonly int $sequence = 0,
        public readonly bool $onActivePath = true,
        public readonly ?string $authorName = null,
        public readonly ?string $contentType = null,
        public readonly ?string $modelSlug = null,
        public readonly ?Carbon $createdAt = null,
        public readonly array $contentBlocks = [],
        public readonly array $attachments = [],
        public readonly array $providerMetadata = [],
    ) {}

    /**
     * Map a provider's own role vocabulary onto the neutral one.
     *
     * Anything unrecognized becomes 'unknown' rather than defaulting to 'user'
     * or 'assistant'. A misattributed message is worse than an unattributed one:
     * it would let the corpus claim a person said something they did not.
     */
    public static function normalizeRole(?string $raw): string
    {
        return match (mb_strtolower(trim((string) $raw))) {
            'user', 'human' => self::ROLE_USER,
            'assistant', 'model', 'ai', 'bot' => self::ROLE_ASSISTANT,
            'system' => self::ROLE_SYSTEM,
            'tool', 'function', 'tool_result', 'tool_use' => self::ROLE_TOOL,
            default => self::ROLE_UNKNOWN,
        };
    }

    public function isEmpty(): bool
    {
        return trim($this->text) === '' && $this->attachments === [];
    }

    /**
     * Content hash over the fields that define what this message is.
     *
     * Deliberately excludes sequence and active-path membership: a message whose
     * body and authorship are unchanged is the same message even if a later
     * export re-orders branches around it.
     */
    public function contentHash(): string
    {
        return hash('sha256', implode("\x1f", [
            $this->providerMessageId,
            $this->role,
            $this->authorName ?? '',
            $this->contentType ?? '',
            $this->modelSlug ?? '',
            $this->createdAt?->toIso8601String() ?? '',
            $this->text,
            json_encode(array_map(
                static fn (NormalizedAttachment $a) => $a->toArray(),
                $this->attachments,
            )) ?: '',
        ]));
    }
}
