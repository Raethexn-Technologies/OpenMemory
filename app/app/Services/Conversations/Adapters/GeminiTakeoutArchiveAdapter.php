<?php

namespace App\Services\Conversations\Adapters;

use App\Services\Conversations\Archive\ArchiveSource;
use App\Services\Conversations\Json\JsonArrayStreamReader;
use App\Services\Conversations\NormalizedConversation;
use App\Services\Conversations\NormalizedMessage;
use Generator;

/**
 * Parses a Google Takeout export of Gemini Apps activity.
 *
 * How to obtain one, as of September 2026: at takeout.google.com, deselect all,
 * choose My Activity, open its per-product selector, deselect all, select Gemini
 * Apps, and change the activity record format from HTML to JSON. Takeout
 * defaults to HTML, and the HTML variant carries no schema at all, so choosing
 * JSON is the step people most often miss. The result lands at
 * "Takeout/My Activity/Gemini Apps/MyActivity.json".
 *
 * This is the one provider in scope whose export is not a conversation export.
 * Google's My Activity schema reference documents the generic activity record
 * shape but does not cover Gemini Apps, and an activity log has no notion of a
 * conversation: each record is a single timestamped turn.
 *
 * The consequence is recorded rather than papered over. Every record becomes one
 * conversation containing at most two messages, and its provider metadata is
 * stamped with grain "activity_record". Grouping records into multi-turn threads
 * by proximity in time would be an invention, and an invented thread boundary is
 * exactly the kind of error that corrupts later reasoning about how a discussion
 * developed. The raw record is preserved, so a future parser can group turns
 * properly if Google ever publishes a conversation identifier, without anyone
 * needing to re-export.
 *
 * Observed record shape:
 *   header        "Gemini Apps"
 *   title         the action, such as "Prompted Gemini"
 *   subtitles[]   {name, value} pairs; value holds the user's prompt text
 *   safeHtmlItem[] {html} entries holding Gemini's response as HTML
 *   time          ISO 8601 timestamp
 */
final class GeminiTakeoutArchiveAdapter extends AbstractArchiveAdapter
{
    public const PROVIDER = 'gemini';

    private const PARSER_VERSION = 'gemini-takeout-1';

    private const ACTIVITY_ENTRY = 'MyActivity.json';

    private const HTML_ENTRY = 'MyActivity.html';

    public function provider(): string
    {
        return self::PROVIDER;
    }

    public function displayName(): string
    {
        return 'Gemini';
    }

    public function parserVersion(): string
    {
        return self::PARSER_VERSION;
    }

    public function detect(ArchiveSource $source): AdapterDetection
    {
        $entry = $this->findGeminiActivityEntry($source);

        if ($entry !== null) {
            return AdapterDetection::supported($entry, 90, 'activity_log');
        }

        // A Takeout export left in its default format. This is recognized and
        // refused with the specific fix, because the alternative is a user
        // concluding that their export is unreadable.
        foreach ($source->entries() as $candidate) {
            if (str_contains(mb_strtolower($candidate), 'gemini') && basename($candidate) === self::HTML_ENTRY) {
                return AdapterDetection::unsupported(
                    'This Takeout export contains MyActivity.html rather than MyActivity.json. '
                    . 'Re-run the export from takeout.google.com and change the activity record format to JSON. '
                    . 'The HTML variant has no documented structure, so parsing it would guess at your history rather than read it.',
                    80,
                );
            }
        }

        return AdapterDetection::noMatch();
    }

    public function conversations(ArchiveSource $source, AdapterDetection $detection): Generator
    {
        $entry = $detection->entry ?? $this->findGeminiActivityEntry($source);

        if ($entry === null) {
            return;
        }

        $stream = $source->stream($entry);
        $nonGemini = 0;

        try {
            $reader = new JsonArrayStreamReader($stream);

            foreach ($reader->elements() as $index => $raw) {
                $decoded = json_decode($raw, true);

                if (! is_array($decoded)) {
                    $this->warn("Skipped activity record at position {$index}: the record is not valid JSON.");

                    continue;
                }

                // A combined Takeout export can hold other products in the same
                // file. Those records belong to a different history and are not
                // imported here.
                if (! $this->isGeminiRecord($decoded)) {
                    $nonGemini++;

                    continue;
                }

                $conversation = $this->normalizeRecord($decoded, $raw, $index);

                if ($conversation !== null) {
                    yield $conversation;
                }
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($nonGemini > 0) {
            $this->warn("Ignored {$nonGemini} activity record(s) from Google products other than Gemini Apps.");
        }
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function normalizeRecord(array $record, string $raw, int $index): ?NormalizedConversation
    {
        $time = $this->parseIso($record['time'] ?? null);
        $warnings = [];

        $prompt = $this->readPrompt($record);
        $response = $this->readResponse($record);

        if ($prompt === '' && $response === '') {
            $this->warn("Skipped activity record at position {$index}: it carried neither a prompt nor a response.");

            return null;
        }

        if ($time === null) {
            $warnings[] = 'Activity record had no readable timestamp.';
        }

        $conversationId = $this->synthesizeId(
            'om',
            self::PROVIDER,
            (string) ($record['time'] ?? ''),
            $prompt,
            $response,
        );

        $messages = [];
        $sequence = 0;

        if ($prompt !== '') {
            $messages[] = new NormalizedMessage(
                providerMessageId: $conversationId . '-user',
                role: NormalizedMessage::ROLE_USER,
                text: $prompt,
                sequence: $sequence++,
                createdAt: $time,
                contentBlocks: [['type' => 'text', 'chars' => mb_strlen($prompt)]],
            );
        }

        if ($response !== '') {
            $messages[] = new NormalizedMessage(
                providerMessageId: $conversationId . '-assistant',
                role: NormalizedMessage::ROLE_ASSISTANT,
                text: $response,
                parentProviderMessageId: $prompt !== '' ? $conversationId . '-user' : null,
                sequence: $sequence,
                createdAt: $time,
                contentBlocks: [['type' => 'text', 'chars' => mb_strlen($response), 'source' => 'safeHtmlItem']],
            );
        } else {
            $warnings[] = 'Activity record contained a prompt but no recorded response.';
        }

        return new NormalizedConversation(
            provider: self::PROVIDER,
            providerConversationId: $conversationId,
            messages: $messages,
            title: $this->titleFor($prompt, $record),
            createdAt: $time,
            updatedAt: $time,
            providerMetadata: array_merge(
                ['grain' => 'activity_record'],
                $this->pickMetadata($record, ['header', 'title', 'titleUrl', 'products', 'activityControls']),
            ),
            rawPayload: $raw,
            warnings: $warnings,
        );
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function readPrompt(array $record): string
    {
        $subtitles = $record['subtitles'] ?? null;

        if (! is_array($subtitles)) {
            return '';
        }

        $pieces = [];

        foreach ($subtitles as $item) {
            if (! is_array($item)) {
                continue;
            }

            $value = $item['value'] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $pieces[] = $this->cleanText($value);
            }
        }

        return implode("\n\n", array_filter($pieces));
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function readResponse(array $record): string
    {
        $items = $record['safeHtmlItem'] ?? null;

        if (! is_array($items)) {
            return '';
        }

        $pieces = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $html = $item['html'] ?? null;

            if (is_string($html) && trim($html) !== '') {
                $text = $this->htmlToText($html);

                if ($text !== '') {
                    $pieces[] = $text;
                }
            }
        }

        return implode("\n\n", $pieces);
    }

    /**
     * Convert a stored HTML response to plain text.
     *
     * Gemini's activity records hold the response as HTML, which is the only
     * markup in any of the supported archives. It is converted rather than
     * stored, for two reasons. Storing markup would make the corpus
     * inconsistent, since no other provider supplies any. And rendering
     * provider-supplied HTML anywhere in the review UI would turn an imported
     * archive into a script injection vector against the person reading it.
     *
     * Script and style bodies are removed rather than stripped of their tags,
     * so their contents never reach the text either.
     */
    private function htmlToText(string $html): string
    {
        $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $text = preg_replace('#<br\s*/?>#i', "\n", $text) ?? $text;
        $text = preg_replace('#</(p|div|li|h[1-6]|tr)>#i', "\n", $text) ?? $text;
        $text = preg_replace('#<li\b[^>]*>#i', '- ', $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return $this->cleanText($text);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function titleFor(string $prompt, array $record): ?string
    {
        if ($prompt !== '') {
            $firstLine = trim(explode("\n", $prompt)[0]);

            if ($firstLine !== '') {
                return mb_substr($firstLine, 0, 200);
            }
        }

        return $this->stringOrNull($record['title'] ?? null, 200);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function isGeminiRecord(array $record): bool
    {
        $header = $record['header'] ?? null;

        if (is_string($header) && str_contains(mb_strtolower($header), 'gemini')) {
            return true;
        }

        $products = $record['products'] ?? null;

        if (is_array($products)) {
            foreach ($products as $product) {
                if (is_string($product) && str_contains(mb_strtolower($product), 'gemini')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Locate MyActivity.json belonging to Gemini Apps.
     *
     * The path check comes first because a Takeout export can contain a
     * MyActivity.json for several products. When the path gives no hint, the
     * first record's header decides.
     */
    private function findGeminiActivityEntry(ArchiveSource $source): ?string
    {
        $fallback = null;

        foreach ($source->entries() as $entry) {
            if (basename($entry) !== self::ACTIVITY_ENTRY) {
                continue;
            }

            if (str_contains(mb_strtolower($entry), 'gemini')) {
                return $entry;
            }

            $fallback ??= $entry;
        }

        if ($fallback !== null && $this->firstRecordIsGemini($source, $fallback)) {
            return $fallback;
        }

        return null;
    }

    private function firstRecordIsGemini(ArchiveSource $source, string $entry): bool
    {
        $stream = $source->stream($entry);

        try {
            $reader = new JsonArrayStreamReader($stream);

            foreach ($reader->elements() as $raw) {
                $decoded = json_decode($raw, true);

                return is_array($decoded) && $this->isGeminiRecord($decoded);
            }
        } catch (\Throwable) {
            return false;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return false;
    }
}
