<?php

namespace Tests\Unit;

use App\Services\GraphExtractionService;
use App\Services\LLM\LlmService;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class GraphExtractionServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_extract_parses_and_normalizes_graph_fields(): void
    {
        $llm = Mockery::mock(LlmService::class);
        $llm->shouldReceive('chat')->once()->andReturn(implode("\n", [
            'NODE_TYPE: project',
            'LABEL: Open Memory Agent',
            'TAGS: Memory, Graph, graph',
            'PEOPLE: Alice Example, Bob Builder',
            'PROJECTS: OMA Core',
        ]));

        $service = new GraphExtractionService($llm);

        $result = $service->extract('I am building Open Memory Agent with Alice.', 'private');

        $this->assertSame([
            'type' => 'project',
            'label' => 'Open Memory Agent',
            'tags' => ['memory', 'graph'],
            'people' => ['Alice Example', 'Bob Builder'],
            'projects' => ['OMA Core'],
            'sensitivity' => 'private',
        ], $result);
    }

    public function test_extract_treats_none_lists_as_empty(): void
    {
        $llm = Mockery::mock(LlmService::class);
        $llm->shouldReceive('chat')->once()->andReturn(implode("\n", [
            'NODE_TYPE: memory',
            'LABEL: General preference',
            'TAGS: NONE',
            'PEOPLE: NONE',
            'PROJECTS: NONE',
        ]));

        $service = new GraphExtractionService($llm);

        $result = $service->extract('I prefer short meetings.', 'public');

        $this->assertSame([], $result['tags']);
        $this->assertSame([], $result['people']);
        $this->assertSame([], $result['projects']);
    }

    public function test_extract_returns_null_when_node_type_is_missing(): void
    {
        $llm = Mockery::mock(LlmService::class);
        $llm->shouldReceive('chat')->once()->andReturn(implode("\n", [
            'LABEL: Missing node type',
            'TAGS: graph',
            'PEOPLE: NONE',
            'PROJECTS: NONE',
        ]));

        $service = new GraphExtractionService($llm);

        $this->assertNull($service->extract('This should not parse.', 'public'));
    }
}
