<?php

namespace App\Services\Ingest;

/**
 * Compress arbitrary text before it crosses an expensive boundary.
 *
 * Two boundaries cost real money in this project:
 *   1. LLM token spend (every prompt and every chunk we summarise)
 *   2. ICP canister update calls (cycles burn per write; storage is metered)
 *
 * Inputs reach us as messy payloads — HTML scraped from issue trackers,
 * git diffs with trailing whitespace, markdown copy-pasted from chat
 * clients, URLs that carry tracking parameters longer than the link text.
 * Without compression we ship that noise verbatim through both boundaries.
 *
 * The preprocessor is intentionally pure: no LLM call, no IO, no state.
 * That keeps it cheap enough to run on every write path and trivially
 * testable. The reductions are conservative — strip noise we can identify
 * with confidence, leave semantic content alone.
 */
class ContentPreprocessor
{
    /**
     * Compress text and return both the result and a per-stage byte report.
     *
     * @return array{
     *   text: string,
     *   original_bytes: int,
     *   final_bytes: int,
     *   reduction_pct: float,
     *   stages: array<string, int>,
     * }
     */
    public function compress(string $text): array
    {
        $original = strlen($text);
        $stages = [];

        $text = $this->stripHtml($text);
        $stages['after_html_strip'] = strlen($text);

        $text = $this->shortenUrls($text);
        $stages['after_url_shorten'] = strlen($text);

        $text = $this->collapseWhitespace($text);
        $stages['after_whitespace'] = strlen($text);

        $text = $this->dedupeLines($text);
        $stages['after_dedupe'] = strlen($text);

        $final = strlen($text);
        $reduction = $original > 0 ? (1 - $final / $original) * 100 : 0.0;

        return [
            'text'           => $text,
            'original_bytes' => $original,
            'final_bytes'    => $final,
            'reduction_pct'  => round($reduction, 1),
            'stages'         => $stages,
        ];
    }

    /**
     * Shortcut: return only the compressed text.
     */
    public function compressText(string $text): string
    {
        return $this->compress($text)['text'];
    }

    private function stripHtml(string $text): string
    {
        // Tag stripping only runs when there is something tag-shaped — most
        // inputs are plain text and there is no reason to walk regex on them.
        if (preg_match('/<[a-zA-Z\/!]/', $text)) {
            // Drop script/style blocks entirely (their content is noise, not text).
            $text = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $text) ?? $text;

            // Replace block-level tags with newlines so paragraph structure survives.
            $text = preg_replace('/<\/?(p|br|div|li|tr|h[1-6])[^>]*>/i', "\n", $text) ?? $text;

            $text = strip_tags($text);
        }

        // Entity decoding runs unconditionally — text can carry &amp;/&lt;
        // without any tags around it (e.g. a commit message about HTML).
        // Skipping the decode would leave 5-byte sequences for 1-byte chars.
        if (str_contains($text, '&')) {
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return $text;
    }

    /**
     * Replace long URLs with their host + a short path hint. We keep enough
     * to identify the destination but stop shipping query strings full of
     * UTM parameters, session tokens, and tracking ids.
     */
    private function shortenUrls(string $text): string
    {
        return preg_replace_callback(
            '/\bhttps?:\/\/[^\s<>"\']+/i',
            function (array $match): string {
                $url = $match[0];
                if (strlen($url) <= 60) {
                    return $url;
                }

                $parts = parse_url($url);
                if (! is_array($parts) || empty($parts['host'])) {
                    return $url;
                }

                $host = $parts['host'];
                $path = $parts['path'] ?? '';
                if (strlen($path) > 30) {
                    $path = substr($path, 0, 27) . '...';
                }

                $scheme = $parts['scheme'] ?? 'https';

                return "{$scheme}://{$host}{$path}";
            },
            $text,
        ) ?? $text;
    }

    private function collapseWhitespace(string $text): string
    {
        // Normalise line endings before any other whitespace work.
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Trim trailing spaces from every line (common in pasted diffs and editor exports).
        $text = preg_replace('/[ \t]+(?=\n|$)/', '', $text) ?? $text;

        // Collapse runs of three or more newlines down to two — preserves
        // paragraph breaks but kills the "padded with empty lines" pattern.
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        // Squeeze runs of inline whitespace (excluding newlines) to a single space.
        $text = preg_replace('/[ \t]{2,}/', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * Drop consecutive duplicate lines. Useful for log dumps, navigation
     * menus stripped to text, and copy-pasted content with repeated blocks.
     * Only consecutive duplicates are removed — distant repeats may be
     * meaningful (e.g. a heading recurring across sections).
     */
    private function dedupeLines(string $text): string
    {
        $lines = explode("\n", $text);
        $out = [];
        $previous = null;

        foreach ($lines as $line) {
            $key = trim($line);
            if ($key !== '' && $key === $previous) {
                continue;
            }
            $out[] = $line;
            $previous = $key;
        }

        return implode("\n", $out);
    }
}
