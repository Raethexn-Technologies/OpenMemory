<?php

namespace App\Services\Conversations\Adapters;

use App\Services\Conversations\Archive\ArchiveSource;
use App\Services\Conversations\NormalizedConversation;
use Generator;

/**
 * Contract every provider archive parser implements.
 *
 * The interface is small on purpose. Adding a provider should mean writing one
 * class and one fixture, not threading a new conditional through the import
 * service, the models, the retrieval path, and the UI. Nothing outside this
 * package knows which providers exist.
 *
 * Adapter obligations:
 *
 *   1. detect() decides whether the adapter understands the archive, and says
 *      why not when it recognizes the provider but cannot parse the file.
 *   2. conversations() yields one NormalizedConversation at a time. It must
 *      stream: returning an array would defeat the streaming reader beneath it.
 *   3. Unknown fields are preserved under providerMetadata rather than dropped.
 *   4. Records that cannot be normalized are reported through the conversation's
 *      warnings or the adapter's own warning list. Nothing is discarded silently.
 *   5. Identifiers are stable across exports. When the provider supplies one it
 *      is used verbatim; when it does not, the adapter derives one deterministically
 *      from content so that re-importing converges instead of duplicating.
 *   6. Adapters perform no network calls, no model calls, and no writes.
 */
interface ConversationArchiveAdapter
{
    /**
     * Short provider key stored on every row: 'chatgpt', 'claude', 'gemini'.
     */
    public function provider(): string;

    /**
     * Human-readable provider name for reports and the UI.
     */
    public function displayName(): string;

    /**
     * Parser version, bumped whenever normalization output changes.
     *
     * Stored per conversation so a later import can tell which rows were
     * produced by an older parser and would benefit from being re-read.
     */
    public function parserVersion(): string;

    public function detect(ArchiveSource $source): AdapterDetection;

    /**
     * Stream normalized conversations out of a detected archive.
     *
     * @return Generator<int, NormalizedConversation>
     */
    public function conversations(ArchiveSource $source, AdapterDetection $detection): Generator;

    /**
     * Archive-level problems found while parsing, collected after iteration.
     *
     * @return array<int, string>
     */
    public function warnings(): array;
}
