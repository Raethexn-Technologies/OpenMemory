<?php

namespace App\Services\Conversations\Archive;

use ZipArchive;

/**
 * ZIP-backed archive source.
 *
 * Every provider export in scope arrives as a ZIP. The entry index is validated
 * once at open time so that a malicious archive fails before any parsing starts:
 *
 *   - entry count against maxEntries
 *   - per-entry uncompressed size against maxEntryUncompressedBytes
 *   - total uncompressed size against maxTotalUncompressedBytes
 *   - per-entry compression ratio against maxCompressionRatio, which is what
 *     catches a decompression bomb whose declared sizes look reasonable in
 *     isolation but expand absurdly
 *   - entry names through ArchiveEntryName
 *
 * Entries that fail name validation are dropped from the index and counted, not
 * silently ignored: refusedEntries() is reported so an operator can see that the
 * archive contained something the importer would not touch.
 */
final class ZipArchiveSource implements ArchiveSource
{
    private ZipArchive $zip;

    /** @var array<string, array{index: int, size: int, compressed: int}> */
    private array $index = [];

    /** @var array<int, array{name: string, reason: string}> */
    private array $refused = [];

    private int $totalBytes = 0;

    private ?string $sha256 = null;

    private function __construct(
        private readonly string $path,
        private readonly ArchiveLimits $limits,
    ) {
        $this->zip = new ZipArchive();
    }

    public static function open(string $path, ArchiveLimits $limits): self
    {
        $source = new self($path, $limits);
        $source->openZip();
        $source->buildIndex();

        return $source;
    }

    public function entries(): array
    {
        return array_keys($this->index);
    }

    /**
     * Entries excluded because their names were unsafe.
     *
     * @return array<int, array{name: string, reason: string}>
     */
    public function refusedEntries(): array
    {
        return $this->refused;
    }

    public function has(string $entry): bool
    {
        return isset($this->index[$entry]);
    }

    public function stream(string $entry)
    {
        $this->assertKnown($entry);

        $stream = $this->zip->getStream($entry);

        if (! is_resource($stream)) {
            throw new ArchiveException("Unable to open archive entry: {$entry}");
        }

        return $stream;
    }

    public function read(string $entry, int $maxBytes = 4194304): string
    {
        $this->assertKnown($entry);

        if ($this->index[$entry]['size'] > $maxBytes) {
            throw new ArchiveException(sprintf(
                'Archive entry %s is %d bytes, above the %d byte limit for a direct read.',
                $entry,
                $this->index[$entry]['size'],
                $maxBytes,
            ));
        }

        $contents = $this->zip->getFromName($entry, $maxBytes);

        if ($contents === false) {
            throw new ArchiveException("Unable to read archive entry: {$entry}");
        }

        return $contents;
    }

    public function sizeOf(string $entry): int
    {
        $this->assertKnown($entry);

        return $this->index[$entry]['size'];
    }

    public function label(): string
    {
        return basename($this->path);
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
        return $this->totalBytes;
    }

    public function close(): void
    {
        $this->zip->close();
    }

    private function openZip(): void
    {
        if (! is_file($this->path)) {
            throw new ArchiveException("Archive not found: {$this->path}");
        }

        $result = $this->zip->open($this->path, ZipArchive::RDONLY);

        if ($result !== true) {
            throw new ArchiveException("Unable to open ZIP archive ({$this->path}): error code {$result}.");
        }
    }

    private function buildIndex(): void
    {
        $count = $this->zip->numFiles;

        if ($count > $this->limits->maxEntries) {
            $this->zip->close();

            throw new ArchiveException(sprintf(
                'Archive declares %d entries, above the configured limit of %d.',
                $count,
                $this->limits->maxEntries,
            ));
        }

        for ($i = 0; $i < $count; $i++) {
            $stat = $this->zip->statIndex($i);

            if ($stat === false) {
                continue;
            }

            $rawName = (string) $stat['name'];
            $name = ArchiveEntryName::normalize($rawName);

            if ($name === null) {
                // Directory entries are uninteresting; anything else that fails
                // normalization is a name the importer refuses to handle.
                if (! str_ends_with(str_replace('\\', '/', $rawName), '/')) {
                    $this->refused[] = ['name' => $rawName, 'reason' => 'unsafe_entry_name'];
                }

                continue;
            }

            $size = (int) $stat['size'];
            $compressed = (int) $stat['comp_size'];

            if ($size > $this->limits->maxEntryUncompressedBytes) {
                $this->zip->close();

                throw new ArchiveException(sprintf(
                    'Archive entry %s expands to %d bytes, above the per-entry limit of %d.',
                    $name,
                    $size,
                    $this->limits->maxEntryUncompressedBytes,
                ));
            }

            // Ratio check. Small entries are exempt because a few hundred bytes
            // of highly repetitive text can legitimately compress very hard.
            if ($compressed > 0 && $size > 65536) {
                $ratio = $size / $compressed;

                if ($ratio > $this->limits->maxCompressionRatio) {
                    $this->zip->close();

                    throw new ArchiveException(sprintf(
                        'Archive entry %s has a compression ratio of %.1f:1, above the limit of %.1f:1.',
                        $name,
                        $ratio,
                        $this->limits->maxCompressionRatio,
                    ));
                }
            }

            $this->totalBytes += $size;

            if ($this->totalBytes > $this->limits->maxTotalUncompressedBytes) {
                $this->zip->close();

                throw new ArchiveException(sprintf(
                    'Archive expands to more than the configured total limit of %d bytes.',
                    $this->limits->maxTotalUncompressedBytes,
                ));
            }

            $this->index[$name] = ['index' => $i, 'size' => $size, 'compressed' => $compressed];
        }

        ksort($this->index);
    }

    private function assertKnown(string $entry): void
    {
        if (! isset($this->index[$entry])) {
            throw new ArchiveException("Unknown archive entry: {$entry}");
        }
    }
}
