<?php

namespace Tests\Unit;

use App\Models\EvidenceFact;
use App\Models\MemoryNode;
use App\Services\EvidenceRetrievalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EvidenceRetrievalServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_retrieve_returns_query_matching_public_document_facts(): void
    {
        $document = $this->makeNode('user-1', 'document_anchor', 'document', 'Pricing Policy');
        $chunk = $this->makeNode('user-1', 'document', 'concept', 'Pricing Chunk');

        $matching = EvidenceFact::create([
            'user_id' => 'user-1',
            'source_node_id' => $chunk->id,
            'source_document_id' => $document->id,
            'fact_text' => 'The 2026 enterprise price is $100 per seat.',
            'span_start' => 10,
            'span_end' => 42,
            'confidence' => 0.9,
        ]);

        EvidenceFact::create([
            'user_id' => 'user-1',
            'source_node_id' => $chunk->id,
            'source_document_id' => $document->id,
            'fact_text' => 'The cancellation notice period is 30 days.',
            'confidence' => 0.9,
        ]);

        $result = app(EvidenceRetrievalService::class)
            ->retrieve('user-1', 'What is the enterprise price?', [$chunk->id]);

        $this->assertCount(1, $result);
        $this->assertSame($matching->id, $result[0]['fact_id']);
        $this->assertSame('Pricing Policy', $result[0]['source_label']);
        $this->assertSame(10, $result[0]['span_start']);
        $this->assertGreaterThan(0, $result[0]['score']);
    }

    public function test_retrieve_excludes_private_non_document_other_user_and_outside_candidate_facts(): void
    {
        $publicDocument = $this->makeNode('user-1', 'document_anchor', 'document', 'Public Policy');
        $publicChunk = $this->makeNode('user-1', 'document', 'concept', 'Public Chunk');
        $outsideChunk = $this->makeNode('user-1', 'document', 'concept', 'Outside Chunk');
        $privateChunk = $this->makeNode('user-1', 'document', 'concept', 'Private Chunk', 'private');
        $chatNode = $this->makeNode('user-1', 'chat', 'memory', 'Chat Node');
        $otherUserChunk = $this->makeNode('user-2', 'document', 'concept', 'Other User Chunk');

        $kept = EvidenceFact::create([
            'user_id' => 'user-1',
            'source_node_id' => $publicChunk->id,
            'source_document_id' => $publicDocument->id,
            'fact_text' => 'The support plan includes enterprise response times.',
            'confidence' => 1.0,
        ]);

        foreach ([$outsideChunk, $privateChunk, $chatNode] as $node) {
            EvidenceFact::create([
                'user_id' => 'user-1',
                'source_node_id' => $node->id,
                'source_document_id' => $publicDocument->id,
                'fact_text' => 'The support plan includes enterprise response times.',
                'confidence' => 1.0,
            ]);
        }

        EvidenceFact::create([
            'user_id' => 'user-2',
            'source_node_id' => $otherUserChunk->id,
            'fact_text' => 'The support plan includes enterprise response times.',
            'confidence' => 1.0,
        ]);

        $result = app(EvidenceRetrievalService::class)
            ->retrieve('user-1', 'enterprise support response', [$publicChunk->id, $privateChunk->id, $chatNode->id]);

        $this->assertSame([$kept->id], array_column($result, 'fact_id'));
    }

    public function test_retrieve_returns_empty_when_query_has_no_fact_overlap(): void
    {
        $document = $this->makeNode('user-1', 'document_anchor', 'document', 'Security Policy');
        $chunk = $this->makeNode('user-1', 'document', 'concept', 'Security Chunk');

        EvidenceFact::create([
            'user_id' => 'user-1',
            'source_node_id' => $chunk->id,
            'source_document_id' => $document->id,
            'fact_text' => 'The security policy requires quarterly access reviews.',
            'confidence' => 1.0,
        ]);

        $result = app(EvidenceRetrievalService::class)
            ->retrieve('user-1', 'vacation meal reimbursement', [$chunk->id]);

        $this->assertSame([], $result);
    }

    private function makeNode(
        string $userId,
        string $source,
        string $type,
        string $label,
        string $sensitivity = 'public',
    ): MemoryNode {
        return MemoryNode::create([
            'user_id' => $userId,
            'type' => $type,
            'sensitivity' => $sensitivity,
            'label' => $label,
            'content' => "{$label} content",
            'tags' => [],
            'confidence' => 1.0,
            'source' => $source,
        ]);
    }
}
