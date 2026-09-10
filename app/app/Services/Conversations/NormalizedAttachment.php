<?php

namespace App\Services\Conversations;

/**
 * Attachment metadata carried by a normalized message.
 *
 * This records what the archive said about a file, not the file itself. Provider
 * archives reference attachments in several ways and only sometimes ship the
 * bytes. Recording the reference keeps the message honest about what was in it
 * without pretending the content is available.
 */
final class NormalizedAttachment
{
    public function __construct(
        public readonly ?string $id = null,
        public readonly ?string $name = null,
        public readonly ?string $mediaType = null,
        public readonly ?int $sizeBytes = null,
        public readonly ?string $pointer = null,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'id' => $this->id,
            'name' => $this->name,
            'media_type' => $this->mediaType,
            'size_bytes' => $this->sizeBytes,
            'pointer' => $this->pointer,
            'width' => $this->width,
            'height' => $this->height,
        ], static fn ($value) => $value !== null);
    }
}
