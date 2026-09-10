<?php

namespace App\Services\Conversations\Archive;

/**
 * Read-only access to the contents of a provider archive.
 *
 * Adapters see archives only through this interface, which gives them three
 * guarantees they would otherwise each have to implement:
 *
 *   1. Entry names are already normalized and validated. A name returned by
 *      entries() contains no absolute path, no drive letter, no backslash, and
 *      no parent-directory segment, so an adapter cannot be tricked into
 *      escaping the archive.
 *   2. Size and ratio limits have already been enforced. Opening a stream cannot
 *      expand into a decompression bomb.
 *   3. Nothing is written to disk. A ZIP is read through per-entry streams, so
 *      importing does not stage an unpacked copy of a person's history anywhere.
 */
interface ArchiveSource
{
    /**
     * Entry names relative to the archive root, in a stable order.
     *
     * @return array<int, string>
     */
    public function entries(): array;

    public function has(string $entry): bool;

    /**
     * Open a read stream for one entry.
     *
     * @return resource
     */
    public function stream(string $entry);

    /**
     * Read an entry expected to be small, such as a manifest.
     *
     * @throws ArchiveException when the entry exceeds $maxBytes.
     */
    public function read(string $entry, int $maxBytes = 4194304): string;

    public function sizeOf(string $entry): int;

    /**
     * Human-readable label for reports, normally the archive filename.
     */
    public function label(): string;

    /**
     * Absolute local path of the archive, or null when it has none.
     */
    public function path(): ?string;

    /**
     * SHA-256 over the archive bytes, or null when the source is a directory.
     */
    public function sha256(): ?string;

    public function totalBytes(): int;

    public function close(): void;
}
