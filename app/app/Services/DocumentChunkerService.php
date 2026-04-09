<?php

namespace App\Services;

/**
 * Splits raw document text into semantic chunks suitable for graph extraction.
 *
 * The chunker operates in two passes:
 *
 *   1. Paragraph pass - splits on double newlines, merges small consecutive
 *      paragraphs into a single chunk up to MAX_CHUNK_CHARS, and discards
 *      structural noise (headers, horizontal rules) below MIN_CHUNK_CHARS.
 *
 *   2. Sentence pass - any paragraph that exceeds MAX_CHUNK_CHARS on its own
 *      is split at sentence boundaries (.  !  ?) so the resulting chunks still
 *      carry coherent semantic units rather than arbitrary character slices.
 *
 * The target chunk size (~1500 chars) is calibrated to fit comfortably within
 * a single GraphExtractionService LLM call without losing context. At ~4 chars
 * per token, 1500 chars ~ 375 tokens, well inside any model's effective range
 * for structured metadata extraction.
 *
 * No external library is required. The chunker does not tokenise; it works
 * on raw character counts, which is sufficient for the extraction use case.
 */
class DocumentChunkerService
{
    // Target maximum characters per chunk. Tuned for GraphExtractionService prompt fit.
    private const MAX_CHUNK_CHARS = 1500;

    // Chunks below this threshold are discarded as structural noise:
    // markdown headers, horizontal rules, lone list bullets, etc.
    private const MIN_CHUNK_CHARS = 60;

    /**
     * Chunk a document into extractable semantic units.
     *
     * @return string[] Non-empty array of chunk strings, each at least MIN_CHUNK_CHARS.
     */
    public function chunk(string $text): array
    {
        $text = $this->normalise($text);

        if (mb_strlen($text) < self::MIN_CHUNK_CHARS) {
            return [];
        }

        $paragraphs = preg_split('/\n{2,}/', $text) ?: [];
        $chunks = [];
        $current = '';

        foreach ($paragraphs as $para) {
            $para = trim($para);

            if (mb_strlen($para) < self::MIN_CHUNK_CHARS) {
                // Too short to be meaningful on its own. If a current chunk is
                // accumulating, append it so short sentences don't become orphans.
                if ($current !== '' && mb_strlen($current) + mb_strlen($para) + 2 <= self::MAX_CHUNK_CHARS) {
                    $current .= "\n\n" . $para;
                }
                continue;
            }

            if (mb_strlen($para) > self::MAX_CHUNK_CHARS) {
                // Flush the accumulator before handling the oversized paragraph.
                if ($current !== '') {
                    $chunks[] = $current;
                    $current = '';
                }
                foreach ($this->splitBySentence($para) as $part) {
                    $chunks[] = $part;
                }
                continue;
            }

            // Normal paragraph: try to merge into the accumulating chunk.
            if ($current !== '' && mb_strlen($current) + mb_strlen($para) + 2 > self::MAX_CHUNK_CHARS) {
                $chunks[] = $current;
                $current = $para;
            } else {
                $current .= ($current !== '' ? "\n\n" : '') . $para;
            }
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return array_values(array_filter(
            $chunks,
            static fn (string $c) => mb_strlen(trim($c)) >= self::MIN_CHUNK_CHARS,
        ));
    }

    /**
     * Normalise raw document text before chunking.
     *
     * Strips YAML frontmatter (Obsidian/Jekyll convention), markdown horizontal
     * rules, and collapses excessive blank lines. Does not strip markdown
     * formatting (bold, italic, links) because the LLM handles those fine and
     * stripping them risks losing semantic content embedded in anchor text.
     */
    private function normalise(string $text): string
    {
        // Strip YAML/TOML frontmatter block (--- ... --- or +++ ... +++)
        $text = preg_replace('/^(?:---|\+\+\+)\s*\n.*?\n(?:---|\+\+\+)\s*\n/s', '', $text) ?? $text;

        // Strip markdown horizontal rules on their own line (---, ***, ___)
        $text = preg_replace('/^[-*_]{3,}\s*$/m', '', $text) ?? $text;

        // Collapse 3+ consecutive blank lines to 2
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * Split a single oversized paragraph at sentence boundaries.
     *
     * Splits on ". ", "! ", "? " patterns. Sentences that individually exceed
     * MAX_CHUNK_CHARS are stored as-is rather than split mid-word.
     *
     * @return string[]
     */
    private function splitBySentence(string $text): array
    {
        $sentences = preg_split('/(?<=[.!?])\s+/', $text) ?: [$text];
        $chunks = [];
        $current = '';

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if ($sentence === '') {
                continue;
            }

            if (mb_strlen($current) + mb_strlen($sentence) + 1 > self::MAX_CHUNK_CHARS) {
                if ($current !== '') {
                    $chunks[] = $current;
                }
                $current = $sentence;
            } else {
                $current .= ($current !== '' ? ' ' : '') . $sentence;
            }
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return array_values(array_filter(
            $chunks,
            static fn (string $c) => mb_strlen(trim($c)) >= self::MIN_CHUNK_CHARS,
        ));
    }
}
