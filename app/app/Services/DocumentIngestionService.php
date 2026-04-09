<?php

namespace App\Services;

use App\Models\MemoryNode;
use Illuminate\Support\Facades\Log;

/**
 * Ingests a document into the memory graph.
 *
 * Pipeline per document:
 *
 *   1. DocumentChunkerService splits the raw text into semantic chunks.
 *   2. One document anchor node (type: 'document') is created first. This node
 *      represents the source file and becomes a hub that all chunk nodes connect
 *      to via 'part_of' edges, making the document traceable in graph traversal.
 *   3. For each chunk, GraphExtractionService extracts structured metadata
 *      (type, label, tags, people, projects). The LLM classifies each chunk
 *      independently so a single document can produce nodes of mixed types
 *      (concept, task, event, goal) rather than everything being tagged 'document'.
 *   4. Each chunk node is stored via MemoryGraphService::storeNode(), which
 *      auto-wires tag-based similarity edges to existing nodes across the graph.
 *      This is where cross-document and chat-to-document connections emerge.
 *   5. A 'part_of' edge is wired from each chunk node to the document anchor.
 *      Edge weight 0.9 reflects a strong structural relationship.
 *
 * MemorabilityService is deliberately bypassed for document chunks. That
 * classifier is calibrated for chat turns (greetings, filler, repeated facts)
 * and its novelty check is a content comparison against recent memory nodes,
 * not a structural quality check. Document chunks are already curated content;
 * the right filter is structural (minimum length, successful LLM extraction),
 * not conversational.
 *
 * Sensitivity is uniform across all chunks: the caller specifies it at ingest
 * time and every resulting node inherits it. This mirrors the real-world model
 * where document sensitivity is a property of the source, not individual passages.
 */
class DocumentIngestionService
{
    public function __construct(
        private readonly DocumentChunkerService $chunker,
        private readonly GraphExtractionService $graphExtractor,
        private readonly MemoryGraphService $graphService,
    ) {}

    /**
     * Ingest a document and return ingestion statistics.
     *
     * @return array{
     *   document_node_id: string,
     *   document_label: string,
     *   chunks_total: int,
     *   nodes_created: int,
     *   chunks_skipped: int,
     * }
     */
    public function ingest(
        string $userId,
        string $title,
        string $text,
        string $sensitivity = 'public',
    ): array {
        $chunks = $this->chunker->chunk($text);
        $total = count($chunks);

        $anchorNode = $this->createAnchorNode($userId, $title, $text, $sensitivity);

        $nodesCreated = 0;
        $skipped = 0;

        foreach ($chunks as $index => $chunk) {
            $extracted = $this->graphExtractor->extract($chunk, $sensitivity);

            if ($extracted === null) {
                // Extraction returned an unparseable response. Skip rather than
                // store a malformed node. Logged inside GraphExtractionService.
                Log::warning('DocumentIngestionService: skipping chunk after extraction failure', [
                    'document_node_id' => $anchorNode->id,
                    'chunk_index'      => $index,
                    'chunk_preview'    => mb_substr($chunk, 0, 80),
                ]);
                $skipped++;
                continue;
            }

            $chunkNode = $this->graphService->storeNode(
                userId:    $userId,
                content:   $chunk,
                extracted: $extracted,
                source:    'document',
                metadata:  [
                    'source_document_id' => $anchorNode->id,
                    'chunk_index'        => $index,
                ],
            );

            // Wire the chunk to its source document anchor at high weight.
            // The 'part_of' relationship makes the document hub traceable via
            // BFS from any chunk node - useful for the graph explorer's
            // neighborhood expansion and for future document-scoped retrieval.
            $this->graphService->createRelationship(
                userId:       $userId,
                fromNodeId:   $chunkNode->id,
                toNodeId:     $anchorNode->id,
                relationship: 'part_of',
                weight:       0.9,
            );

            $nodesCreated++;
        }

        return [
            'document_node_id' => $anchorNode->id,
            'document_label'   => $anchorNode->label,
            'chunks_total'     => $total,
            'nodes_created'    => $nodesCreated,
            'chunks_skipped'   => $skipped,
        ];
    }

    /**
     * Create the document anchor node that represents the source file.
     *
     * The anchor uses a short preview of the document as its content rather
     * than the full text, which keeps the node useful in flat memory retrieval
     * without flooding the context window with the entire document body.
     */
    private function createAnchorNode(
        string $userId,
        string $title,
        string $text,
        string $sensitivity,
    ): MemoryNode {
        $preview = mb_substr(strip_tags($text), 0, 300);
        $label = mb_substr($title, 0, 120);

        // source='document_anchor' distinguishes this hub node from chunk nodes
        // (source='document') so DocumentController::index() can list only anchors
        // without being fooled by chunks the LLM classifies as type='document'.
        //
        // tags=[] prevents wireTagEdges() from creating same_topic_as edges between
        // unrelated document anchors via the structural tags 'document'/'ingested'.
        // Cross-document connections emerge through chunk nodes, not anchors.
        return $this->graphService->storeNode(
            userId:    $userId,
            content:   "Document: {$label}. {$preview}",
            extracted: [
                'type'        => 'document',
                'label'       => $label,
                'tags'        => [],
                'people'      => [],
                'projects'    => [],
                'sensitivity' => $sensitivity,
            ],
            source: 'document_anchor',
        );
    }
}
