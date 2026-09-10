<?php

namespace App\Services\Conversations\Adapters;

use App\Services\Conversations\Archive\ArchiveEntryName;
use App\Services\Conversations\Archive\ArchiveSource;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Shared parsing helpers for provider adapters.
 *
 * These exist so that every adapter agrees on the things that must not vary
 * between providers: how a timestamp becomes a Carbon instance, what counts as
 * clean text, and how a deterministic identifier is derived when a provider
 * supplies none. Divergence on any of those would make cross-provider timeline
 * reasoning quietly wrong.
 */
abstract class AbstractArchiveAdapter implements ConversationArchiveAdapter
{
    /** @var array<int, string> */
    protected array $warnings = [];

    public function warnings(): array
    {
        return array_values(array_unique($this->warnings));
    }

    protected function warn(string $message): void
    {
        // Bound the list so a systematically broken archive cannot turn the
        // import report itself into a memory problem.
        if (count($this->warnings) < 200) {
            $this->warnings[] = $message;
        }
    }

    /**
     * Find the first entry with the given basename, at any depth.
     */
    protected function findEntry(ArchiveSource $source, string $basename): ?string
    {
        foreach ($source->entries() as $entry) {
            if (ArchiveEntryName::isNamed($entry, $basename)) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Parse a Unix timestamp expressed in seconds, with or without a fraction.
     *
     * Returns null for anything that is not a plausible timestamp rather than
     * guessing. A conversation with no usable time is more honest than one
     * stamped with the epoch or with "now".
     */
    protected function parseUnixSeconds(mixed $value): ?Carbon
    {
        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
            return null;
        }

        $seconds = (float) $value;

        // Milliseconds are common enough in exports to be worth detecting, and
        // the two ranges do not overlap for any date this corpus can contain.
        if ($seconds > 100000000000.0) {
            $seconds /= 1000.0;
        }

        if ($seconds <= 0.0 || $seconds > 4102444800.0) {
            return null;
        }

        try {
            return Carbon::createFromTimestampUTC((int) $seconds);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Parse an ISO 8601 timestamp string into UTC.
     */
    protected function parseIso(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Normalize message text for storage.
     *
     * Line endings are unified and C0 control characters other than tab and
     * newline are stripped. Those characters serve no purpose in a transcript
     * and are a reliable way to smuggle invisible content past a reviewer who
     * is reading the same text in a browser.
     */
    protected function cleanText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);

        return trim((string) $text);
    }

    /**
     * Derive a stable identifier when the provider supplies none.
     *
     * The prefix makes synthesized identifiers obvious in the database and in
     * the UI, so nobody mistakes one for something the provider actually issued.
     */
    protected function synthesizeId(string $prefix, string ...$parts): string
    {
        return $prefix . '-' . substr(hash('sha256', implode("\x1f", $parts)), 0, 32);
    }

    /**
     * Read a string field, returning null for anything else.
     */
    protected function stringOrNull(mixed $value, int $maxLength = 500): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $clean = $this->cleanText($value);

        if ($clean === '') {
            return null;
        }

        return mb_substr($clean, 0, $maxLength);
    }

    /**
     * Keep only the provider fields worth preserving, dropping bulk content.
     *
     * providerMetadata exists to stop normalization from losing information, not
     * to become a second copy of the transcript. Values that are themselves large
     * structures are replaced with a shape marker.
     *
     * @param  array<string, mixed>  $source
     * @param  array<int, string>  $keys
     * @return array<string, mixed>
     */
    protected function pickMetadata(array $source, array $keys): array
    {
        $picked = [];

        foreach ($keys as $key) {
            if (! array_key_exists($key, $source)) {
                continue;
            }

            $value = $source[$key];

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            if (is_scalar($value)) {
                $picked[$key] = is_string($value) ? mb_substr($value, 0, 500) : $value;

                continue;
            }

            if (is_array($value)) {
                $encoded = json_encode($value);

                if (is_string($encoded) && strlen($encoded) <= 2000) {
                    $picked[$key] = $value;
                } else {
                    $picked[$key] = ['_omitted' => 'oversized', '_bytes' => is_string($encoded) ? strlen($encoded) : null];
                }
            }
        }

        return $picked;
    }
}
