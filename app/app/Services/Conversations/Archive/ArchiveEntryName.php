<?php

namespace App\Services\Conversations\Archive;

/**
 * Validation and normalization of archive entry names.
 *
 * A ZIP entry name is attacker-controlled text. The classic failure is path
 * traversal, where an entry called "../../../.ssh/authorized_keys" escapes the
 * extraction directory. OpenMemory never extracts to disk, which removes the
 * direct version of that attack, but entry names still reach logs, reports, the
 * review UI, and adapter matching logic, so they are validated anyway.
 *
 * Rejected outright: absolute paths, Windows drive letters, UNC prefixes,
 * parent-directory segments, embedded null bytes, and names long enough to be a
 * denial-of-service attempt against downstream display code.
 */
final class ArchiveEntryName
{
    private const MAX_LENGTH = 1024;

    /**
     * Normalize a raw entry name, or return null when it must be refused.
     *
     * Directory entries return null: they carry no content and only widen the
     * set of names an adapter has to reason about.
     */
    public static function normalize(string $raw): ?string
    {
        if ($raw === '' || mb_strlen($raw) > self::MAX_LENGTH) {
            return null;
        }

        if (str_contains($raw, "\0")) {
            return null;
        }

        $name = str_replace('\\', '/', $raw);

        // Directory entry.
        if (str_ends_with($name, '/')) {
            return null;
        }

        // Absolute POSIX path, UNC path, or Windows drive letter.
        if (str_starts_with($name, '/') || str_starts_with($name, '//') || preg_match('/^[A-Za-z]:/', $name) === 1) {
            return null;
        }

        $segments = explode('/', $name);
        $clean = [];

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                return null;
            }

            $clean[] = $segment;
        }

        if ($clean === []) {
            return null;
        }

        return implode('/', $clean);
    }

    /**
     * True when the entry sits at the given basename, at any depth.
     *
     * Provider archives are inconsistent about whether they wrap everything in a
     * top-level folder, so matching is by basename rather than by full path.
     */
    public static function isNamed(string $entry, string $basename): bool
    {
        return mb_strtolower(basename($entry)) === mb_strtolower($basename);
    }
}
