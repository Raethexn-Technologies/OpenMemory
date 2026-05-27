<?php

namespace App\Services\Ingest;

use App\Services\GraphExtractionService;
use App\Services\IcpMemoryService;
use App\Services\MemoryGraphService;
use App\Services\RedactionService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orchestrates an ingest run: fetch items from a source, redact, distil each
 * into a memory, persist to the graph, and (in mock mode) to ICP.
 *
 * Trust boundaries the pipeline enforces, matching the chat/document paths:
 *
 *   1. Redaction runs BEFORE any LLM call. Commit messages, file diffs, and
 *      similar payloads can carry credentials, emails, tokens. We refuse to
 *      ship raw text to the LLM or to the canister.
 *   2. The LLM-derived sensitivity classification is enforced by
 *      RedactionService::enforceSensitivity so a "public" classification on
 *      text that contained floor-redacted categories gets promoted upward.
 *   3. Only public memories are written automatically. Private/sensitive
 *      items are logged and discarded — there is no approval flow for
 *      ingest yet, and silently storing them would bypass the contract the
 *      chat path holds (browser-signed writes for non-public memories).
 *   4. ICP storage runs only in mock mode. Live mode writes must be
 *      browser-signed; the canister rejects adapter-relayed /store calls
 *      (icp/adapter/server.js line 104). Graph storage runs in all modes,
 *      so retrieval still sees ingested memories.
 *
 * Cursor discipline: the pipeline returns the newest external_id whose
 * processing reached a terminal state without error. The caller advances
 * the source's dedup cursor to that point — items that errored remain
 * eligible for the next sweep.
 */
class IngestPipeline
{
    public function __construct(
        private readonly IngestSummarizer $summarizer,
        private readonly IcpMemoryService $icp,
        private readonly RedactionService $redactor,
        private readonly GraphExtractionService $graphExtractor,
        private readonly MemoryGraphService $graphService,
    ) {}

    /**
     * Process a batch of fetched items.
     *
     * @param  array<int, array{source_label: string, external_id: string, text: string, metadata: array}>  $items
     * @return array{
     *   processed: int,
     *   stored: int,
     *   skipped: int,
     *   non_public_dropped: int,
     *   errors: int,
     *   last_success_external_id: string|null,
     * }
     */
    public function run(string $userId, string $sessionId, array $items): array
    {
        $stored = 0;
        $skipped = 0;
        $nonPublicDropped = 0;
        $errors = 0;
        $lastSuccessExternalId = null;

        foreach ($items as $item) {
            $reached_terminal = $this->processItem($userId, $sessionId, $item, $stored, $skipped, $nonPublicDropped, $errors);

            if ($reached_terminal) {
                // Track the newest non-erroring external_id so the caller can
                // advance the dedup cursor exactly to that point. Items come
                // in newest-first; we keep the first terminal one we see.
                $lastSuccessExternalId ??= $item['external_id'];
            }
        }

        return [
            'processed'                => count($items),
            'stored'                   => $stored,
            'skipped'                  => $skipped,
            'non_public_dropped'       => $nonPublicDropped,
            'errors'                   => $errors,
            'last_success_external_id' => $lastSuccessExternalId,
        ];
    }

    /**
     * Process a single item end-to-end. Returns true when the item reached
     * a terminal state (stored or deliberately dropped) so the caller can
     * advance the cursor past it. Returns false on any error.
     */
    private function processItem(
        string $userId,
        string $sessionId,
        array $item,
        int &$stored,
        int &$skipped,
        int &$nonPublicDropped,
        int &$errors,
    ): bool {
        try {
            // 1. Redact raw payload BEFORE it touches the LLM.
            $redaction = $this->redactor->redact($item['text'], $userId);
            $redactedText = $redaction->text;

            // 2. Distil into a memory line + classification.
            $extract = $this->summarizer->extract($item['source_label'], $redactedText);
        } catch (Throwable $e) {
            Log::warning('IngestPipeline: redact/summarise failure', [
                'source' => $item['source_label'] ?? '?',
                'error'  => $e->getMessage(),
            ]);
            $errors++;
            return false;
        }

        if ($extract === null) {
            $skipped++;
            return true;
        }

        // 3. Promote sensitivity if floor-redacted categories appeared.
        // Without this a "public" classification on a commit message that
        // contained an API key would still leak the key category as public.
        $effectiveType = $this->redactor->enforceSensitivity($extract['type'], $redaction);

        // 4. Non-public items have no approval flow on the ingest path yet.
        // Mirror DocumentController's behaviour: log, discard, do not store.
        // When the approval flow is wired this branch becomes "persist to a
        // pending table and surface to the user", not silent drop.
        if ($effectiveType !== 'public') {
            Log::info('IngestPipeline: dropping non-public item (approval flow not implemented)', [
                'source'         => $item['source_label'],
                'external_id'    => $item['external_id'],
                'classification' => $effectiveType,
            ]);
            $nonPublicDropped++;
            return true;
        }

        // 5. Persist. Graph storage runs in all modes — ICP only in mock mode
        // because live writes require browser signing. See class docblock.
        try {
            $metadataForCanister = array_merge(
                $item['metadata'],
                $redaction->applied() ? ['redaction' => $redaction->toMetadata()] : [],
            );

            $this->writeGraphNode(
                userId:        $userId,
                sessionId:     $sessionId,
                content:       $extract['content'],
                sourceLabel:   $item['source_label'],
                extraMetadata: $metadataForCanister,
            );

            if ($this->icp->isMockMode()) {
                $this->icp->storeMemory(
                    userId:     $userId,
                    sessionId:  $sessionId,
                    content:    $extract['content'],
                    metadata:   json_encode($metadataForCanister),
                    memoryType: 'public',
                );
            }

            $stored++;
            return true;
        } catch (Throwable $e) {
            Log::warning('IngestPipeline: persistence failure', [
                'source' => $item['source_label'],
                'error'  => $e->getMessage(),
            ]);
            $errors++;
            return false;
        }
    }

    /**
     * Write the ingest memory into the local graph so retrieval can see it.
     *
     * Uses GraphExtractionService for structured metadata; falls back to a
     * minimal node if extraction can't parse the LLM response — losing graph
     * detail is acceptable, losing the memory is not.
     */
    private function writeGraphNode(
        string $userId,
        string $sessionId,
        string $content,
        string $sourceLabel,
        array $extraMetadata,
    ): void {
        $extracted = $this->graphExtractor->extract($content, 'public');

        if ($extracted === null) {
            // Extraction failed — store a minimal node so the memory still
            // lands in the graph. The shape matches GraphExtractionService
            // output so storeNode() can consume it unchanged.
            $extracted = [
                'type'        => 'memory',
                'label'       => mb_substr($content, 0, 60),
                'tags'        => ['ingest'],
                'people'      => [],
                'projects'    => [],
                'sensitivity' => 'public',
            ];
        }

        $this->graphService->storeNode(
            userId:    $userId,
            content:   $content,
            extracted: $extracted,
            sessionId: $sessionId,
            source:    'ingest:' . $sourceLabel,
            metadata:  $extraMetadata,
        );
    }
}
