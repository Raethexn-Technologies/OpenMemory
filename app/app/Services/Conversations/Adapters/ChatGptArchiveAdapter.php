<?php

namespace App\Services\Conversations\Adapters;

use App\Services\Conversations\Archive\ArchiveSource;
use App\Services\Conversations\Json\JsonArrayStreamReader;
use App\Services\Conversations\NormalizedAttachment;
use App\Services\Conversations\NormalizedConversation;
use App\Services\Conversations\NormalizedMessage;
use Generator;

/**
 * Parses an OpenAI ChatGPT data export.
 *
 * How to obtain one, as of September 2026: in ChatGPT, open Settings, then Data
 * Controls, then Export data. OpenAI emails a link to a ZIP. The file that
 * matters is conversations.json; the archive also contains chat.html, user.json,
 * message_feedback.json, and any images referenced by conversations.
 *
 * The format is not a published API. OpenAI documents how to request an export,
 * not what the JSON contains, and the structure has changed several times.
 * This parser therefore treats every field as optional, verifies shapes before
 * reading them, and records a warning rather than throwing when it meets
 * something it does not recognize. Fixtures in tests/Fixtures/conversations
 * encode the shape this parser was written against; when the format moves, the
 * fixtures are what a maintainer updates first.
 *
 * The structural feature that matters most is that a ChatGPT conversation is a
 * tree, not a list. Editing a prompt forks the conversation, so one conversation
 * can hold several divergent paths. current_node points at the leaf of the path
 * the user was last looking at. This parser walks that path back to the root to
 * establish the active branch, then appends every other reachable node so the
 * abandoned branches survive too. Flattening the tree into whichever order the
 * mapping object happened to serialize in would silently interleave alternative
 * answers with the real conversation.
 */
final class ChatGptArchiveAdapter extends AbstractArchiveAdapter
{
    public const PROVIDER = 'chatgpt';

    private const PARSER_VERSION = 'chatgpt-1';

    private const CONVERSATIONS_ENTRY = 'conversations.json';

    /**
     * Content types whose parts are ordinary readable text.
     *
     * Anything outside this list is still recorded structurally, but its body is
     * not treated as conversation prose.
     */
    private const TEXT_CONTENT_TYPES = ['text', 'multimodal_text', 'code', 'execution_output', 'reasoning_recap', 'thoughts'];

    public function provider(): string
    {
        return self::PROVIDER;
    }

    public function displayName(): string
    {
        return 'ChatGPT';
    }

    public function parserVersion(): string
    {
        return self::PARSER_VERSION;
    }

    public function detect(ArchiveSource $source): AdapterDetection
    {
        $entry = $this->findEntry($source, self::CONVERSATIONS_ENTRY);

        if ($entry === null) {
            return AdapterDetection::noMatch();
        }

        // Both ChatGPT and Claude name their file conversations.json, so the
        // name alone is not enough. The discriminator is the mapping tree.
        $probe = $this->probe($source, $entry);

        if ($probe === null) {
            return AdapterDetection::noMatch();
        }

        if (! isset($probe['mapping']) || ! is_array($probe['mapping'])) {
            return AdapterDetection::noMatch();
        }

        return AdapterDetection::supported($entry, 95);
    }

    public function conversations(ArchiveSource $source, AdapterDetection $detection): Generator
    {
        $entry = $detection->entry ?? $this->findEntry($source, self::CONVERSATIONS_ENTRY);

        if ($entry === null) {
            return;
        }

        $stream = $source->stream($entry);

        try {
            $reader = new JsonArrayStreamReader($stream);

            foreach ($reader->elements() as $index => $raw) {
                $decoded = json_decode($raw, true);

                if (! is_array($decoded)) {
                    $this->warn("Skipped conversation at position {$index}: the record is not valid JSON.");

                    continue;
                }

                $conversation = $this->normalizeConversation($decoded, $raw, $index);

                if ($conversation !== null) {
                    yield $conversation;
                }
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function normalizeConversation(array $record, string $raw, int $index): ?NormalizedConversation
    {
        $mapping = $record['mapping'] ?? null;

        if (! is_array($mapping)) {
            $this->warn("Skipped conversation at position {$index}: no mapping object.");

            return null;
        }

        $conversationId = $this->conversationId($record, $raw);
        $warnings = [];

        $ordered = $this->orderNodes($mapping, $record['current_node'] ?? null);
        $messages = [];
        $sequence = 0;

        foreach ($ordered as [$nodeId, $onActivePath]) {
            $node = $mapping[$nodeId];

            if (! is_array($node)) {
                continue;
            }

            $message = $this->normalizeMessage($node, $nodeId, $sequence, $onActivePath, $warnings);

            if ($message === null) {
                continue;
            }

            $messages[] = $message;
            $sequence++;
        }

        if ($messages === []) {
            // Empty conversations are real: a chat opened and abandoned exports
            // with a mapping containing only a root placeholder. They are kept
            // so conversation counts match what the provider reported.
            $warnings[] = 'Conversation contains no readable messages.';
        }

        return new NormalizedConversation(
            provider: self::PROVIDER,
            providerConversationId: $conversationId,
            messages: $messages,
            title: $this->stringOrNull($record['title'] ?? null),
            createdAt: $this->parseUnixSeconds($record['create_time'] ?? null),
            updatedAt: $this->parseUnixSeconds($record['update_time'] ?? null),
            workspaceLabel: $this->workspaceLabel($record),
            providerMetadata: $this->pickMetadata($record, [
                'default_model_slug',
                'conversation_template_id',
                'gizmo_id',
                'gizmo_type',
                'is_archived',
                'is_starred',
                'memory_scope',
                'is_do_not_remember',
                'safe_urls',
                'voice',
                'current_node',
            ]),
            rawPayload: $raw,
            warnings: $warnings,
        );
    }

    /**
     * Order the mapping nodes: the active path first, then everything else.
     *
     * Walking back from current_node gives the branch the user actually kept.
     * Nodes not on that path belong to edited-away branches; they are appended
     * in a deterministic depth-first order so re-importing the same archive
     * produces the same sequence numbers.
     *
     * @param  array<string, mixed>  $mapping
     * @return array<int, array{0: string, 1: bool}>
     */
    private function orderNodes(array $mapping, mixed $currentNode): array
    {
        $activePath = [];

        if (is_string($currentNode) && isset($mapping[$currentNode])) {
            $cursor = $currentNode;
            $walked = [];

            // A malformed export can contain a parent cycle. Stopping on the
            // first repeat both terminates the walk and keeps the path free of
            // duplicate nodes, which a bare iteration counter would not.
            while (is_string($cursor) && isset($mapping[$cursor]) && ! isset($walked[$cursor])) {
                $walked[$cursor] = true;
                $activePath[] = $cursor;
                $node = $mapping[$cursor];
                $cursor = is_array($node) ? ($node['parent'] ?? null) : null;
            }

            $activePath = array_reverse($activePath);
        }

        $ordered = [];
        $seen = [];

        foreach ($activePath as $nodeId) {
            if (isset($seen[$nodeId])) {
                continue;
            }

            $ordered[] = [$nodeId, true];
            $seen[$nodeId] = true;
        }

        foreach ($this->depthFirstOrder($mapping) as $nodeId) {
            if (! isset($seen[$nodeId])) {
                $ordered[] = [$nodeId, false];
                $seen[$nodeId] = true;
            }
        }

        return $ordered;
    }

    /**
     * Deterministic depth-first traversal from the roots of the mapping.
     *
     * @param  array<string, mixed>  $mapping
     * @return array<int, string>
     */
    private function depthFirstOrder(array $mapping): array
    {
        $roots = [];

        foreach ($mapping as $nodeId => $node) {
            $parent = is_array($node) ? ($node['parent'] ?? null) : null;

            if (! is_string($parent) || ! isset($mapping[$parent])) {
                $roots[] = (string) $nodeId;
            }
        }

        sort($roots);

        $order = [];
        $visited = [];
        $stack = array_reverse($roots);

        while ($stack !== []) {
            $nodeId = array_pop($stack);

            if (isset($visited[$nodeId]) || ! isset($mapping[$nodeId])) {
                continue;
            }

            $visited[$nodeId] = true;
            $order[] = $nodeId;

            $node = $mapping[$nodeId];
            $children = is_array($node) ? ($node['children'] ?? []) : [];

            if (! is_array($children)) {
                continue;
            }

            foreach (array_reverse($children) as $child) {
                if (is_string($child) && ! isset($visited[$child])) {
                    $stack[] = $child;
                }
            }
        }

        // Nodes unreachable from any root still belong to the conversation.
        foreach (array_keys($mapping) as $nodeId) {
            if (! isset($visited[(string) $nodeId])) {
                $order[] = (string) $nodeId;
            }
        }

        return $order;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<int, string>  $warnings
     */
    private function normalizeMessage(array $node, string $nodeId, int $sequence, bool $onActivePath, array &$warnings): ?NormalizedMessage
    {
        $message = $node['message'] ?? null;

        // Structural placeholders carry no message. They are the tree's scaffolding.
        if (! is_array($message)) {
            return null;
        }

        $author = is_array($message['author'] ?? null) ? $message['author'] : [];
        $role = NormalizedMessage::normalizeRole(is_string($author['role'] ?? null) ? $author['role'] : null);

        if ($role === NormalizedMessage::ROLE_UNKNOWN && isset($author['role'])) {
            $warnings[] = 'Message with an unrecognized author role was imported as role "unknown".';
        }

        $content = is_array($message['content'] ?? null) ? $message['content'] : [];
        $contentType = $this->stringOrNull($content['content_type'] ?? null, 48);

        [$text, $blocks, $attachments] = $this->readContent($content);
        $metadata = is_array($message['metadata'] ?? null) ? $message['metadata'] : [];

        foreach ($this->readAttachmentMetadata($metadata) as $attachment) {
            $attachments[] = $attachment;
        }

        $hidden = ($metadata['is_visually_hidden_from_conversation'] ?? false) === true;
        $weightZero = isset($message['weight']) && (float) $message['weight'] === 0.0;

        if ($text === '' && $attachments === []) {
            // Hidden system scaffolding with no body adds nothing a person could
            // read back later, so it is dropped rather than stored as an empty row.
            return null;
        }

        $providerMessageId = $this->stringOrNull($message['id'] ?? null, 255) ?? $nodeId;

        return new NormalizedMessage(
            providerMessageId: $providerMessageId,
            role: $role,
            text: $text,
            parentProviderMessageId: is_string($node['parent'] ?? null) ? $node['parent'] : null,
            sequence: $sequence,
            onActivePath: $onActivePath,
            authorName: $this->stringOrNull($author['name'] ?? null, 120),
            contentType: $contentType,
            modelSlug: $this->stringOrNull($metadata['model_slug'] ?? null, 120),
            createdAt: $this->parseUnixSeconds($message['create_time'] ?? null),
            contentBlocks: $blocks,
            attachments: $attachments,
            providerMetadata: array_filter([
                'node_id' => $nodeId,
                'status' => $this->stringOrNull($message['status'] ?? null, 48),
                'recipient' => $this->stringOrNull($message['recipient'] ?? null, 64),
                'end_turn' => $message['end_turn'] ?? null,
                'hidden' => $hidden ?: null,
                'zero_weight' => $weightZero ?: null,
            ], static fn ($value) => $value !== null),
        );
    }

    /**
     * Extract readable text, a structural block summary, and inline media refs.
     *
     * @param  array<string, mixed>  $content
     * @return array{0: string, 1: array<int, array<string, mixed>>, 2: array<int, NormalizedAttachment>}
     */
    private function readContent(array $content): array
    {
        $contentType = is_string($content['content_type'] ?? null) ? $content['content_type'] : '';
        $blocks = [];
        $attachments = [];
        $pieces = [];

        // Custom-instruction records store their text under their own keys.
        if ($contentType === 'user_editable_context') {
            foreach (['user_profile', 'user_instructions'] as $key) {
                $value = $content[$key] ?? null;

                if (is_string($value) && trim($value) !== '') {
                    $pieces[] = $this->cleanText($value);
                    $blocks[] = ['type' => $key, 'chars' => mb_strlen($value)];
                }
            }

            return [implode("\n\n", $pieces), $blocks, $attachments];
        }

        $parts = $content['parts'] ?? null;

        if (! is_array($parts)) {
            // Some content types carry their body under a different key. Record
            // the shape so the absence is auditable rather than invisible.
            $blocks[] = ['type' => $contentType !== '' ? $contentType : 'unknown', 'chars' => 0, 'parsed' => false];

            $text = $content['text'] ?? null;

            return [is_string($text) ? $this->cleanText($text) : '', $blocks, $attachments];
        }

        $readable = in_array($contentType, self::TEXT_CONTENT_TYPES, true);

        foreach ($parts as $part) {
            if (is_string($part)) {
                $clean = $this->cleanText($part);

                if ($clean !== '') {
                    $blocks[] = ['type' => 'text', 'chars' => mb_strlen($clean)];

                    if ($readable || $contentType === '') {
                        $pieces[] = $clean;
                    }
                }

                continue;
            }

            if (! is_array($part)) {
                continue;
            }

            $partType = is_string($part['content_type'] ?? null) ? $part['content_type'] : 'unknown';

            if ($partType === 'image_asset_pointer' || $partType === 'audio_asset_pointer' || $partType === 'video_container_asset_pointer') {
                $attachments[] = new NormalizedAttachment(
                    id: $this->assetId($part['asset_pointer'] ?? null),
                    mediaType: $partType,
                    sizeBytes: is_int($part['size_bytes'] ?? null) ? $part['size_bytes'] : null,
                    pointer: $this->stringOrNull($part['asset_pointer'] ?? null, 255),
                    width: is_int($part['width'] ?? null) ? $part['width'] : null,
                    height: is_int($part['height'] ?? null) ? $part['height'] : null,
                );
                $blocks[] = ['type' => $partType, 'chars' => 0];

                continue;
            }

            if (is_string($part['text'] ?? null)) {
                $clean = $this->cleanText($part['text']);

                if ($clean !== '') {
                    $pieces[] = $clean;
                    $blocks[] = ['type' => $partType, 'chars' => mb_strlen($clean)];
                }

                continue;
            }

            $blocks[] = ['type' => $partType, 'chars' => 0, 'parsed' => false];
        }

        return [implode("\n\n", $pieces), $blocks, $attachments];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<int, NormalizedAttachment>
     */
    private function readAttachmentMetadata(array $metadata): array
    {
        $raw = $metadata['attachments'] ?? null;

        if (! is_array($raw)) {
            return [];
        }

        $attachments = [];

        foreach ($raw as $item) {
            if (! is_array($item)) {
                continue;
            }

            $attachments[] = new NormalizedAttachment(
                id: $this->stringOrNull($item['id'] ?? null, 128),
                name: $this->stringOrNull($item['name'] ?? null, 255),
                mediaType: $this->stringOrNull($item['mime_type'] ?? $item['mimeType'] ?? null, 128),
                sizeBytes: is_int($item['size'] ?? null) ? $item['size'] : null,
            );
        }

        return $attachments;
    }

    private function assetId(mixed $pointer): ?string
    {
        if (! is_string($pointer)) {
            return null;
        }

        // Pointers look like "sediment://file_ABC123" or "file-service://file-ABC".
        $position = strrpos($pointer, '/');

        return $position === false ? mb_substr($pointer, 0, 128) : mb_substr(substr($pointer, $position + 1), 0, 128);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function workspaceLabel(array $record): ?string
    {
        // Project conversations carry a template id prefixed "g-p-".
        $template = $record['conversation_template_id'] ?? null;

        if (is_string($template) && str_starts_with($template, 'g-p-')) {
            return mb_substr($template, 0, 255);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function conversationId(array $record, string $raw): string
    {
        foreach (['conversation_id', 'id'] as $key) {
            $value = $record[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return mb_substr(trim($value), 0, 255);
            }
        }

        $this->warn('A conversation had no provider identifier; a deterministic one was derived from its content.');

        return $this->synthesizeId('om', self::PROVIDER, $raw);
    }

    /**
     * Read the first array element cheaply so detection does not parse the file.
     *
     * @return array<string, mixed>|null
     */
    private function probe(ArchiveSource $source, string $entry): ?array
    {
        $stream = $source->stream($entry);

        try {
            $reader = new JsonArrayStreamReader($stream);

            foreach ($reader->elements() as $raw) {
                $decoded = json_decode($raw, true);

                return is_array($decoded) ? $decoded : null;
            }
        } catch (\Throwable) {
            return null;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return null;
    }
}
