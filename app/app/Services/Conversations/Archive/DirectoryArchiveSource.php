<?php

namespace App\Services\Conversations\Archive;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Directory-backed archive source.
 *
 * Users routinely unzip an export before looking at it, and Google Takeout in
 * particular is often delivered as a folder. Pointing the importer at that
 * folder should work exactly as well as pointing it at the ZIP.
 *
 * Symbolic links are skipped rather than followed. A link inside a supplied
 * directory is the directory equivalent of a traversal entry, and there is no
 * legitimate export that depends on one.
 */
final class DirectoryArchiveSource implements ArchiveSource
{
    /** @var array<string, int> */
    private array $index = [];

    private int $totalBytes = 0;

    private function __construct(
        private readonly string $root,
        private readonly ArchiveLimits $limits,
    ) {}

    public static function open(string $path, ArchiveLimits $limits): self
    {
        $real = realpath($path);

        if ($real === false || ! is_dir($real)) {
            throw new ArchiveException("Directory not found: {$path}");
        }

        $source = new self(rtrim(str_replace('\\', '/', $real), '/'), $limits);
        $source->buildIndex();

        return $source;
    }

    public function entries(): array
    {
        return array_keys($this->index);
    }

    public function has(string $entry): bool
    {
        return isset($this->index[$entry]);
    }

    public function stream(string $entry)
    {
        $handle = @fopen($this->absolute($entry), 'rb');

        if (! is_resource($handle)) {
            throw new ArchiveException("Unable to open entry: {$entry}");
        }

        return $handle;
    }

    public function read(string $entry, int $maxBytes = 4194304): string
    {
        $size = $this->sizeOf($entry);

        if ($size > $maxBytes) {
            throw new ArchiveException(sprintf(
                'Entry %s is %d bytes, above the %d byte limit for a direct read.',
                $entry,
                $size,
                $maxBytes,
            ));
        }

        $contents = @file_get_contents($this->absolute($entry));

        if ($contents === false) {
            throw new ArchiveException("Unable to read entry: {$entry}");
        }

        return $contents;
    }

    public function sizeOf(string $entry): int
    {
        if (! isset($this->index[$entry])) {
            throw new ArchiveException("Unknown entry: {$entry}");
        }

        return $this->index[$entry];
    }

    public function label(): string
    {
        return basename($this->root);
    }

    public function path(): ?string
    {
        return $this->root;
    }

    /**
     * Directories have no single byte sequence to hash.
     *
     * Import identity falls back to the per-conversation content hashes, which
     * is what makes re-import idempotent regardless of the container.
     */
    public function sha256(): ?string
    {
        return null;
    }

    public function totalBytes(): int
    {
        return $this->totalBytes;
    }

    public function close(): void
    {
        // Nothing held open.
    }

    private function buildIndex(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $this->root,
                FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS,
            ),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isLink() || ! $file->isFile()) {
                continue;
            }

            $absolute = str_replace('\\', '/', $file->getPathname());
            $relative = ltrim(substr($absolute, strlen($this->root)), '/');
            $name = ArchiveEntryName::normalize($relative);

            if ($name === null) {
                continue;
            }

            $size = (int) $file->getSize();

            if ($size > $this->limits->maxEntryUncompressedBytes) {
                throw new ArchiveException(sprintf(
                    'Entry %s is %d bytes, above the per-entry limit of %d.',
                    $name,
                    $size,
                    $this->limits->maxEntryUncompressedBytes,
                ));
            }

            $this->index[$name] = $size;
            $this->totalBytes += $size;

            if (count($this->index) > $this->limits->maxEntries) {
                throw new ArchiveException(sprintf(
                    'Directory contains more than the configured limit of %d entries.',
                    $this->limits->maxEntries,
                ));
            }

            if ($this->totalBytes > $this->limits->maxTotalUncompressedBytes) {
                throw new ArchiveException(sprintf(
                    'Directory contents exceed the configured total limit of %d bytes.',
                    $this->limits->maxTotalUncompressedBytes,
                ));
            }
        }

        ksort($this->index);
    }

    private function absolute(string $entry): string
    {
        return $this->root . '/' . $entry;
    }
}
