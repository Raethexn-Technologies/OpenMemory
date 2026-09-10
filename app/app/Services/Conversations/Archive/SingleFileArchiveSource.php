<?php

namespace App\Services\Conversations\Archive;

/**
 * Single-file archive source.
 *
 * People frequently keep only the one file that mattered: conversations.json
 * from a ChatGPT or Claude export, or MyActivity.json from Takeout. Refusing
 * those would push users toward re-downloading a full export for no benefit, so
 * a lone JSON file is treated as an archive containing exactly one entry.
 */
final class SingleFileArchiveSource implements ArchiveSource
{
    private ?string $sha256 = null;

    private function __construct(
        private readonly string $path,
        private readonly string $entry,
        private readonly int $size,
    ) {}

    public static function open(string $path, ArchiveLimits $limits): self
    {
        $real = realpath($path);

        if ($real === false || ! is_file($real)) {
            throw new ArchiveException("File not found: {$path}");
        }

        $size = (int) filesize($real);

        if ($size > $limits->maxEntryUncompressedBytes) {
            throw new ArchiveException(sprintf(
                'File %s is %d bytes, above the per-entry limit of %d.',
                basename($real),
                $size,
                $limits->maxEntryUncompressedBytes,
            ));
        }

        return new self(str_replace('\\', '/', $real), basename($real), $size);
    }

    public function entries(): array
    {
        return [$this->entry];
    }

    public function has(string $entry): bool
    {
        return $entry === $this->entry;
    }

    public function stream(string $entry)
    {
        $this->assertKnown($entry);

        $handle = @fopen($this->path, 'rb');

        if (! is_resource($handle)) {
            throw new ArchiveException("Unable to open file: {$this->path}");
        }

        return $handle;
    }

    public function read(string $entry, int $maxBytes = 4194304): string
    {
        $this->assertKnown($entry);

        if ($this->size > $maxBytes) {
            throw new ArchiveException(sprintf(
                'File %s is %d bytes, above the %d byte limit for a direct read.',
                $entry,
                $this->size,
                $maxBytes,
            ));
        }

        $contents = @file_get_contents($this->path);

        if ($contents === false) {
            throw new ArchiveException("Unable to read file: {$this->path}");
        }

        return $contents;
    }

    public function sizeOf(string $entry): int
    {
        $this->assertKnown($entry);

        return $this->size;
    }

    public function label(): string
    {
        return $this->entry;
    }

    public function path(): ?string
    {
        return $this->path;
    }

    public function sha256(): ?string
    {
        if ($this->sha256 === null) {
            $hash = hash_file('sha256', $this->path);
            $this->sha256 = $hash === false ? null : $hash;
        }

        return $this->sha256;
    }

    public function totalBytes(): int
    {
        return $this->size;
    }

    public function close(): void
    {
        // Nothing held open.
    }

    private function assertKnown(string $entry): void
    {
        if ($entry !== $this->entry) {
            throw new ArchiveException("Unknown entry: {$entry}");
        }
    }
}
