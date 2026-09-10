<?php

namespace App\Services\Conversations\Adapters;

/**
 * Result of asking an adapter whether it understands an archive.
 *
 * Detection is deliberately three-valued rather than boolean. "I recognize this
 * provider but cannot import this particular archive" is a different answer from
 * "this is not mine", and conflating them produces the worst possible message
 * for a user: silence. A recognized-but-unsupported archive gets a specific
 * reason, which is how a user learns that their Takeout export is HTML instead
 * of JSON rather than concluding the importer is broken.
 */
final class AdapterDetection
{
    private function __construct(
        public readonly bool $recognized,
        public readonly bool $supported,
        public readonly int $confidence,
        public readonly ?string $entry = null,
        public readonly ?string $reason = null,
        public readonly ?string $variant = null,
    ) {}

    /**
     * @param  int  $confidence  0-100. Used to pick between adapters that both match.
     */
    public static function supported(string $entry, int $confidence = 80, ?string $variant = null): self
    {
        return new self(true, true, $confidence, $entry, null, $variant);
    }

    /**
     * The archive is this provider's, but it cannot be imported as supplied.
     */
    public static function unsupported(string $reason, int $confidence = 60): self
    {
        return new self(true, false, $confidence, null, $reason, null);
    }

    public static function noMatch(): self
    {
        return new self(false, false, 0, null, null, null);
    }
}
