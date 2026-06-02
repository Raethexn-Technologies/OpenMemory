<?php

namespace App\Services;

use App\Models\EvidenceFact;
use App\Models\MemoryNode;
use App\Services\LLM\LlmService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Extracts source-backed atomic facts from document chunks.
 *
 * GraphExtractionService classifies chunks for graph traversal. This service
 * produces the smaller evidence units that grounded retrieval can cite and
 * verify against. The model proposes facts and exact quotes, but this service
 * computes span offsets locally by locating each quote in the source chunk.
 */
class EvidenceFactExtractionService
{
    private const FACT_PROMPT = <<<'PROMPT'
You are an evidence extraction agent for a grounded document QA system.

Extract 3 to 8 atomic factual claims from the provided document chunk. A claim is atomic when it states one fact that can be supported by one short source quote.

Return valid JSON only, with no markdown fences and no surrounding text:
{
  "facts": [
    {
      "fact_text": "one concise factual claim",
      "quote": "exact supporting text copied from the chunk",
      "confidence": 0.0
    }
  ]
}

Rules:
- Only extract facts explicitly supported by the chunk.
- Do not infer facts that are not directly stated.
- The quote must be copied exactly from the chunk.
- Preserve redaction tokens such as [EMAIL] or [PAYMENT_CARD#token].
- Skip vague, promotional, or purely stylistic statements.
- Confidence must be between 0 and 1.
PROMPT;

    public function __construct(
        private readonly LlmService $llm,
    ) {}

    /**
     * Extract facts from a chunk and store them against the created chunk node.
     *
     * Returns the number of evidence facts stored. Extraction failure is treated
     * as non-fatal because the graph chunk itself has already been persisted.
     */
    public function extractAndStoreForNode(
        string $userId,
        MemoryNode $sourceNode,
        ?MemoryNode $sourceDocument,
        string $chunk,
        int $chunkIndex,
    ): int {
        try {
            $facts = $this->extract($chunk);
        } catch (\Throwable $e) {
            Log::warning('EvidenceFactExtractionService: extraction failed', [
                'source_node_id' => $sourceNode->id,
                'chunk_index' => $chunkIndex,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }

        $stored = 0;
        foreach ($facts as $fact) {
            $factText = trim((string) ($fact['fact_text'] ?? ''));
            if (mb_strlen($factText) < 8) {
                continue;
            }

            $quote = trim((string) ($fact['quote'] ?? ''));
            [$spanStart, $spanEnd] = $this->spanForQuote($chunk, $quote);

            EvidenceFact::create([
                'user_id' => $userId,
                'source_node_id' => $sourceNode->id,
                'source_document_id' => $sourceDocument?->id,
                'fact_text' => $factText,
                'span_start' => $spanStart,
                'span_end' => $spanEnd,
                'observed_at' => Carbon::now(),
                'confidence' => $this->confidence($fact['confidence'] ?? 1.0),
                'metadata' => array_filter([
                    'chunk_index' => $chunkIndex,
                    'quote' => $quote !== '' ? $quote : null,
                    'span_source' => $spanStart === null ? 'unmatched_quote' : 'exact_quote',
                ]),
            ]);

            $stored++;
        }

        return $stored;
    }

    /**
     * @return array<int, array{fact_text: string, quote?: string, confidence?: float|int|string}>
     */
    public function extract(string $chunk): array
    {
        if (trim($chunk) === '') {
            return [];
        }

        $messages = [['role' => 'user', 'content' => "Document chunk:\n{$chunk}"]];
        $raw = trim($this->llm->chatFor(LlmService::TASK_REASON, self::FACT_PROMPT, $messages));
        $decoded = $this->decodeJson($raw);

        if (! is_array($decoded)) {
            Log::warning('EvidenceFactExtractionService: unparseable JSON response', [
                'raw' => mb_substr($raw, 0, 300),
            ]);

            return [];
        }

        $facts = $decoded['facts'] ?? $decoded;
        if (! is_array($facts)) {
            return [];
        }

        return array_values(array_filter($facts, static function ($fact) {
            return is_array($fact)
                && isset($fact['fact_text'])
                && is_string($fact['fact_text'])
                && trim($fact['fact_text']) !== '';
        }));
    }

    /**
     * @return array<string, mixed>|array<int, mixed>|null
     */
    private function decodeJson(string $raw): ?array
    {
        $json = preg_replace('/^```(?:json)?\s*/m', '', $raw) ?? $raw;
        $json = preg_replace('/\s*```$/m', '', $json) ?? $json;
        $json = trim($json);

        $decoded = json_decode($json, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $objectStart = strpos($json, '{');
        $objectEnd = strrpos($json, '}');
        if ($objectStart !== false && $objectEnd !== false && $objectEnd > $objectStart) {
            $decoded = json_decode(substr($json, $objectStart, $objectEnd - $objectStart + 1), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $arrayStart = strpos($json, '[');
        $arrayEnd = strrpos($json, ']');
        if ($arrayStart !== false && $arrayEnd !== false && $arrayEnd > $arrayStart) {
            $decoded = json_decode(substr($json, $arrayStart, $arrayEnd - $arrayStart + 1), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    private function spanForQuote(string $chunk, string $quote): array
    {
        if ($quote === '') {
            return [null, null];
        }

        $start = mb_strpos($chunk, $quote);
        if ($start === false) {
            return [null, null];
        }

        return [$start, $start + mb_strlen($quote)];
    }

    private function confidence(mixed $value): float
    {
        $confidence = is_numeric($value) ? (float) $value : 1.0;

        return max(0.0, min(1.0, $confidence));
    }
}
