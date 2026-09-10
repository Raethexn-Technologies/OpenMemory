<?php

namespace App\Services\Conversations\Archive;

/**
 * Chooses the right ArchiveSource for a supplied path.
 *
 * Selection is by what the path actually is, not by extension, so a ZIP named
 * something else still opens as a ZIP and a JSON file named ".zip" still fails
 * with a clear message instead of a parser error twenty frames deeper.
 */
final class ArchiveSourceFactory
{
    public function open(string $path, ?ArchiveLimits $limits = null): ArchiveSource
    {
        $limits ??= ArchiveLimits::fromConfig();

        if ($path === '') {
            throw new ArchiveException('No archive path was supplied.');
        }

        if (is_dir($path)) {
            return DirectoryArchiveSource::open($path, $limits);
        }

        if (! is_file($path)) {
            throw new ArchiveException("Archive not found: {$path}");
        }

        return $this->looksLikeZip($path)
            ? ZipArchiveSource::open($path, $limits)
            : SingleFileArchiveSource::open($path, $limits);
    }

    /**
     * ZIP local file header magic, including the empty-archive variant.
     */
    private function looksLikeZip(string $path): bool
    {
        $handle = @fopen($path, 'rb');

        if (! is_resource($handle)) {
            throw new ArchiveException("Unable to read archive: {$path}");
        }

        $magic = fread($handle, 4);
        fclose($handle);

        return in_array($magic, ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"], true);
    }
}
