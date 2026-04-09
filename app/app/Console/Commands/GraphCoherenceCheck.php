<?php

namespace App\Console\Commands;

use App\Models\MemoryEdge;
use App\Models\MemoryNode;
use App\Services\MemoryGraphService;
use Illuminate\Console\Command;

/**
 * Verifies that document-sourced nodes wire to chat-sourced nodes via shared tags.
 *
 * This command answers the open research question in RESEARCH.md Track 9:
 * "Do document-sourced and chat-sourced nodes form same_topic_as edges when
 * they share tags, or does the Physarum graph remain topologically isolated
 * by source?"
 *
 * The LLM extraction variable is deliberately removed. Rather than ingesting
 * a real document through DocumentIngestionService, which calls
 * GraphExtractionService and introduces vocabulary uncertainty, this command
 * constructs a synthetic chunk node using the tags that already exist in the
 * user's chat nodes. If same_topic_as edges still do not form, the problem is
 * in the graph wiring layer, not the LLM. If they do form, the wiring is
 * source-agnostic and the remaining open question is extraction vocabulary
 * consistency for real ingestion.
 *
 * The synthetic nodes are deleted after the check completes so the graph is not polluted.
 *
 * Usage:
 *   php artisan graph:coherence-check
 *   php artisan graph:coherence-check --user=<user_id>
 *   php artisan graph:coherence-check --keep   # retain test nodes for manual inspection
 */
class GraphCoherenceCheck extends Command
{
    protected $signature = 'graph:coherence-check
        {--user= : User ID to run the check against (defaults to first user with chat nodes)}
        {--keep  : Retain the synthetic test nodes after the check instead of deleting them}';

    protected $description = 'Check whether document-sourced nodes wire to chat-sourced nodes via shared tags.';

    public function handle(MemoryGraphService $graph): int
    {
        $userId = $this->resolveUserId();

        if (! $userId) {
            $this->warn('No chat nodes found. Ingest some chat memories first, then re-run.');
            return Command::FAILURE;
        }

        $this->line("User: {$userId}");

        // Step 1: find the most common tags in existing chat nodes.
        $chatNodes = MemoryNode::where('user_id', $userId)
            ->where('source', 'chat')
            ->whereNull('consolidated_at')
            ->get(['id', 'tags', 'label']);

        $this->line("Chat nodes found: {$chatNodes->count()}");

        if ($chatNodes->isEmpty()) {
            $this->warn('No unconsolidated chat nodes found for this user.');
            return Command::FAILURE;
        }

        $tagFrequency = [];
        foreach ($chatNodes as $node) {
            foreach ($node->tags ?? [] as $tag) {
                $tagFrequency[$tag] = ($tagFrequency[$tag] ?? 0) + 1;
            }
        }

        if (empty($tagFrequency)) {
            $this->warn('Chat nodes exist but none have tags. Cannot run coherence check.');
            return Command::FAILURE;
        }

        arsort($tagFrequency);
        $topTags = array_slice(array_keys($tagFrequency), 0, 4);

        $this->line('Top tags in chat nodes: ' . implode(', ', $topTags));
        $this->newLine();

        // Step 2: create a synthetic document anchor and chunk with those same tags.
        // This bypasses GraphExtractionService deliberately: the goal is to test
        // wireTagEdges() in isolation, not extraction vocabulary consistency.
        $anchorNode = $graph->storeNode(
            userId:    $userId,
            content:   '[COHERENCE CHECK] Synthetic document anchor - safe to delete.',
            extracted: [
                'type'        => 'document',
                'label'       => '[Test] Coherence Check Anchor',
                'tags'        => [],
                'people'      => [],
                'projects'    => [],
                'sensitivity' => 'public',
            ],
            source: 'document_anchor',
        );

        $chunkContent = 'This is a synthetic test chunk created by graph:coherence-check. '
            . 'Topics: ' . implode(', ', $topTags) . '. '
            . 'This chunk should wire to any chat node that shares these tags via same_topic_as edges.';

        $chunkNode = $graph->storeNode(
            userId:    $userId,
            content:   $chunkContent,
            extracted: [
                'type'        => 'memory',
                'label'       => '[Test] Coherence Check Chunk',
                'tags'        => $topTags,
                'people'      => [],
                'projects'    => [],
                'sensitivity' => 'public',
            ],
            source:   'document',
            metadata: ['coherence_check' => true, 'source_document_id' => $anchorNode->id],
        );

        $graph->createRelationship($userId, $chunkNode->id, $anchorNode->id, 'part_of', 0.9);

        // Step 3: read the edges that were wired to the chunk.
        $edges = MemoryEdge::where('user_id', $userId)
            ->where(function ($q) use ($chunkNode) {
                $q->where('from_node_id', $chunkNode->id)
                    ->orWhere('to_node_id', $chunkNode->id);
            })
            ->where('relationship', 'same_topic_as')
            ->get();

        $crossSourceEdges = $edges->filter(function ($edge) use ($chunkNode) {
            $otherId = $edge->from_node_id === $chunkNode->id
                ? $edge->to_node_id
                : $edge->from_node_id;
            $other = MemoryNode::find($otherId);

            return $other && $other->source === 'chat';
        });

        $sameSourceEdges = $edges->filter(function ($edge) use ($chunkNode) {
            $otherId = $edge->from_node_id === $chunkNode->id
                ? $edge->to_node_id
                : $edge->from_node_id;
            $other = MemoryNode::find($otherId);

            return $other && $other->source === 'document';
        });

        // Step 4: report.
        $this->line('same_topic_as edges on chunk node:');
        $this->line("  Total:                {$edges->count()}");
        $this->line("  Chat <-> Document:    {$crossSourceEdges->count()}  <- the cross-source signal");
        $this->line("  Document <-> Document: {$sameSourceEdges->count()}");
        $this->newLine();

        if ($crossSourceEdges->isNotEmpty()) {
            $this->info('COHERENCE CONFIRMED: document chunk wired to chat nodes via shared tags.');
            $this->line('Cross-source edges:');
            foreach ($crossSourceEdges as $edge) {
                $otherId = $edge->from_node_id === $chunkNode->id ? $edge->to_node_id : $edge->from_node_id;
                $other = MemoryNode::find($otherId);
                $sharedTags = array_intersect($topTags, $other->tags ?? []);
                $this->line("  [{$other->source}] {$other->label}");
                $this->line('    shared tags: ' . implode(', ', $sharedTags));
                $this->line("    edge weight: {$edge->weight}");
            }
            $this->newLine();
            $this->line('Finding: wireTagEdges() is source-agnostic. Cross-source connections');
            $this->line('depend on extraction vocabulary consistency, not graph wiring.');
            $this->line('Next step: ingest a real document and verify same_topic_as edges form');
            $this->line('using the LLM-extracted tags rather than known-good injected tags.');
        } else {
            $this->warn('COHERENCE FAILED: no cross-source edges formed.');

            if ($edges->isEmpty()) {
                $this->warn('No same_topic_as edges at all. wireTagEdges() is not firing.');
                $this->line('Check that the chunk node tags array is non-empty and that');
                $this->line('existing chat nodes have overlapping tags in the candidate window.');
                $this->line("Candidate window: last 100 nodes. Chat nodes in window: {$chatNodes->count()}.");
            } else {
                $this->warn('Edges formed, but only within same source. Tag vocabulary is source-specific.');
                $this->line('Shared tags between the test chunk and chat nodes: ' . implode(', ', $topTags));
                $this->line('This is unexpected. These tags were taken directly from chat nodes.');
                $this->line('Investigate: are chat node tags stored as lowercase strings?');
                $this->line('Run: php artisan tinker, then MemoryNode::where(\'source\',\'chat\')->first()->tags');
            }
        }

        // Step 5: clean up synthetic nodes unless --keep is passed.
        if (! $this->option('keep')) {
            $this->newLine();
            $this->line('Cleaning up synthetic test nodes...');
            MemoryEdge::where('user_id', $userId)
                ->where(function ($q) use ($chunkNode, $anchorNode) {
                    $q->whereIn('from_node_id', [$chunkNode->id, $anchorNode->id])
                        ->orWhereIn('to_node_id', [$chunkNode->id, $anchorNode->id]);
                })
                ->delete();
            $chunkNode->delete();
            $anchorNode->delete();
            $this->line('Done. Graph restored to pre-check state.');
        } else {
            $this->line("Test nodes retained (--keep). Anchor: {$anchorNode->id} / Chunk: {$chunkNode->id}");
        }

        return $crossSourceEdges->isNotEmpty() ? Command::SUCCESS : Command::FAILURE;
    }

    private function resolveUserId(): ?string
    {
        if ($userId = $this->option('user')) {
            return $userId;
        }

        return MemoryNode::where('source', 'chat')->value('user_id');
    }
}
