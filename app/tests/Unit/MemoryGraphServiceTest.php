<?php

namespace Tests\Unit;

use App\Models\MemoryEdge;
use App\Models\MemoryNode;
use App\Services\MemoryGraphService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemoryGraphServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_node_creates_similarity_and_same_sensitivity_anchor_edges(): void
    {
        $existing = MemoryNode::create([
            'user_id' => 'user-1',
            'session_id' => 'session-a',
            'type' => 'memory',
            'sensitivity' => 'public',
            'label' => 'Existing graph note',
            'content' => 'Existing graph note',
            'tags' => ['graph', 'laravel'],
            'confidence' => 1.0,
            'source' => 'chat',
        ]);

        $service = app(MemoryGraphService::class);

        $node = $service->storeNode('user-1', 'Alice is helping with Omega graph work.', [
            'type' => 'memory',
            'label' => 'Alice Omega work',
            'tags' => ['graph', 'testing'],
            'people' => ['Alice Johnson'],
            'projects' => ['Omega'],
            'sensitivity' => 'private',
        ], 'session-b');

        $tagEdge = MemoryEdge::where('relationship', 'same_topic_as')->first();
        $personAnchor = MemoryNode::where('type', 'person')->where('label', 'Alice Johnson')->first();
        $projectAnchor = MemoryNode::where('type', 'project')->where('label', 'Omega')->first();

        $this->assertNotNull($tagEdge);
        $this->assertSame($node->id, $tagEdge->from_node_id);
        $this->assertSame($existing->id, $tagEdge->to_node_id);
        $this->assertSame(0.3, $tagEdge->weight);
        $this->assertSame('private', $personAnchor?->sensitivity);
        $this->assertSame('private', $projectAnchor?->sensitivity);
        $this->assertDatabaseHas('memory_edges', [
            'from_node_id' => $node->id,
            'to_node_id' => $personAnchor->id,
            'relationship' => 'about_person',
        ]);
        $this->assertDatabaseHas('memory_edges', [
            'from_node_id' => $node->id,
            'to_node_id' => $projectAnchor->id,
            'relationship' => 'part_of',
        ]);
    }

    public function test_store_node_reuses_existing_anchor_nodes_with_matching_sensitivity(): void
    {
        $service = app(MemoryGraphService::class);

        $service->storeNode('user-1', 'Alice is reviewing the first milestone.', [
            'type' => 'memory',
            'label' => 'Alice milestone',
            'tags' => ['review'],
            'people' => ['Alice Johnson'],
            'projects' => [],
            'sensitivity' => 'public',
        ]);

        $service->storeNode('user-1', 'Alice is preparing the launch notes.', [
            'type' => 'memory',
            'label' => 'Alice launch notes',
            'tags' => ['launch'],
            'people' => ['Alice Johnson'],
            'projects' => [],
            'sensitivity' => 'public',
        ]);

        $this->assertSame(1, MemoryNode::where([
            'user_id' => 'user-1',
            'type' => 'person',
            'label' => 'Alice Johnson',
            'sensitivity' => 'public',
        ])->count());
    }

    public function test_get_graph_filters_nodes_and_edges_by_user_type_and_sensitivity(): void
    {
        $publicMemory = MemoryNode::create([
            'user_id' => 'user-1',
            'type' => 'memory',
            'sensitivity' => 'public',
            'label' => 'Public memory',
            'content' => 'Public memory',
            'tags' => ['alpha'],
            'confidence' => 1.0,
            'source' => 'chat',
        ]);
        $publicProject = MemoryNode::create([
            'user_id' => 'user-1',
            'type' => 'project',
            'sensitivity' => 'public',
            'label' => 'Project node',
            'content' => 'Project node',
            'tags' => ['alpha'],
            'confidence' => 1.0,
            'source' => 'chat',
        ]);
        $privateMemory = MemoryNode::create([
            'user_id' => 'user-1',
            'type' => 'memory',
            'sensitivity' => 'private',
            'label' => 'Private memory',
            'content' => 'Private memory',
            'tags' => ['beta'],
            'confidence' => 1.0,
            'source' => 'chat',
        ]);
        $otherUserNode = MemoryNode::create([
            'user_id' => 'user-2',
            'type' => 'memory',
            'sensitivity' => 'public',
            'label' => 'Other user memory',
            'content' => 'Other user memory',
            'tags' => ['gamma'],
            'confidence' => 1.0,
            'source' => 'chat',
        ]);

        MemoryEdge::create([
            'user_id' => 'user-1',
            'from_node_id' => $publicMemory->id,
            'to_node_id' => $publicProject->id,
            'relationship' => 'part_of',
            'weight' => 0.8,
        ]);
        MemoryEdge::create([
            'user_id' => 'user-1',
            'from_node_id' => $publicMemory->id,
            'to_node_id' => $privateMemory->id,
            'relationship' => 'same_topic_as',
            'weight' => 0.4,
        ]);
        MemoryEdge::create([
            'user_id' => 'user-2',
            'from_node_id' => $otherUserNode->id,
            'to_node_id' => $otherUserNode->id,
            'relationship' => 'related_to',
            'weight' => 0.1,
        ]);

        $service = app(MemoryGraphService::class);

        $publicGraph = $service->getGraph('user-1');
        $memoryOnlyGraph = $service->getGraph('user-1', [
            'types' => ['memory'],
            'sensitivity' => ['public', 'private'],
        ]);

        $this->assertCount(2, $publicGraph['nodes']);
        $this->assertCount(1, $publicGraph['edges']);
        $publicNodeIds = collect($publicGraph['nodes'])->pluck('id')->all();
        sort($publicNodeIds);
        $expectedPublicNodeIds = [$publicMemory->id, $publicProject->id];
        sort($expectedPublicNodeIds);
        $this->assertSame($expectedPublicNodeIds, $publicNodeIds);
        $this->assertCount(2, $memoryOnlyGraph['nodes']);
        $this->assertCount(1, $memoryOnlyGraph['edges']);
        $memoryNodeIds = collect($memoryOnlyGraph['nodes'])->pluck('id')->all();
        sort($memoryNodeIds);
        $expectedMemoryNodeIds = [$publicMemory->id, $privateMemory->id];
        sort($expectedMemoryNodeIds);
        $this->assertSame($expectedMemoryNodeIds, $memoryNodeIds);
    }

    public function test_get_neighborhood_is_scoped_to_user_and_visible_sensitivity(): void
    {
        $root = MemoryNode::create([
            'user_id' => 'user-1',
            'type' => 'memory',
            'sensitivity' => 'public',
            'label' => 'Root node',
            'content' => 'Root node',
            'tags' => ['root'],
            'confidence' => 1.0,
            'source' => 'chat',
        ]);
        $privateNeighbor = MemoryNode::create([
            'user_id' => 'user-1',
            'type' => 'memory',
            'sensitivity' => 'private',
            'label' => 'Private node',
            'content' => 'Private node',
            'tags' => ['private'],
            'confidence' => 1.0,
            'source' => 'chat',
        ]);
        $publicViaPrivate = MemoryNode::create([
            'user_id' => 'user-1',
            'type' => 'project',
            'sensitivity' => 'public',
            'label' => 'Visible through private',
            'content' => 'Visible through private',
            'tags' => ['project'],
            'confidence' => 1.0,
            'source' => 'chat',
        ]);

        MemoryEdge::create([
            'user_id' => 'user-1',
            'from_node_id' => $root->id,
            'to_node_id' => $privateNeighbor->id,
            'relationship' => 'same_topic_as',
            'weight' => 0.6,
        ]);
        MemoryEdge::create([
            'user_id' => 'user-1',
            'from_node_id' => $privateNeighbor->id,
            'to_node_id' => $publicViaPrivate->id,
            'relationship' => 'part_of',
            'weight' => 0.7,
        ]);

        $service = app(MemoryGraphService::class);

        $publicNeighborhood = $service->getNeighborhood('user-1', $root->id, 2);
        $fullNeighborhood = $service->getNeighborhood('user-1', $root->id, 2, [
            'sensitivity' => ['public', 'private'],
        ]);

        $this->assertCount(1, $publicNeighborhood['nodes']);
        $this->assertCount(0, $publicNeighborhood['edges']);
        $this->assertCount(3, $fullNeighborhood['nodes']);
        $this->assertCount(2, $fullNeighborhood['edges']);

        $this->expectException(ModelNotFoundException::class);
        $service->getNeighborhood('user-2', $root->id, 1);
    }
}
