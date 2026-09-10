<?php

namespace App\Services\Conversations\Archive;

/**
 * Safety bounds applied to every archive before parsing begins.
 *
 * Defaults come from config/conversations.php. Tests construct these directly
 * with small values so limit behaviour can be exercised without building
 * multi-gigabyte fixtures.
 */
final class ArchiveLimits
{
    public function __construct(
        public readonly int $maxTotalUncompressedBytes,
        public readonly int $maxEntryUncompressedBytes,
        public readonly int $maxEntries,
        public readonly float $maxCompressionRatio,
        public readonly int $maxJsonElementBytes,
        public readonly int $maxMessageChars,
    ) {}

    public static function fromConfig(): self
    {
        /** @var array<string, mixed> $limits */
        $limits = config('conversations.limits', []);

        return new self(
            maxTotalUncompressedBytes: (int) ($limits['max_total_uncompressed_bytes'] ?? 8 * 1024 * 1024 * 1024),
            maxEntryUncompressedBytes: (int) ($limits['max_entry_uncompressed_bytes'] ?? 4 * 1024 * 1024 * 1024),
            maxEntries: (int) ($limits['max_entries'] ?? 200000),
            maxCompressionRatio: (float) ($limits['max_compression_ratio'] ?? 400.0),
            maxJsonElementBytes: (int) ($limits['max_json_element_bytes'] ?? 64 * 1024 * 1024),
            maxMessageChars: (int) ($limits['max_message_chars'] ?? 200000),
        );
    }

    /**
     * @param  array<string, int|float>  $overrides
     */
    public function with(array $overrides): self
    {
        return new self(
            maxTotalUncompressedBytes: (int) ($overrides['max_total_uncompressed_bytes'] ?? $this->maxTotalUncompressedBytes),
            maxEntryUncompressedBytes: (int) ($overrides['max_entry_uncompressed_bytes'] ?? $this->maxEntryUncompressedBytes),
            maxEntries: (int) ($overrides['max_entries'] ?? $this->maxEntries),
            maxCompressionRatio: (float) ($overrides['max_compression_ratio'] ?? $this->maxCompressionRatio),
            maxJsonElementBytes: (int) ($overrides['max_json_element_bytes'] ?? $this->maxJsonElementBytes),
            maxMessageChars: (int) ($overrides['max_message_chars'] ?? $this->maxMessageChars),
        );
    }
}
