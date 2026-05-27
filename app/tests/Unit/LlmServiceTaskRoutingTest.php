<?php

namespace Tests\Unit;

use App\Services\LLM\LlmProviderInterface;
use App\Services\LLM\LlmService;
use Tests\TestCase;

/**
 * The task router is config-driven dispatch on top of a single provider.
 * These tests pin the routing rules without making any network calls:
 *
 *   - a task with a configured model triggers withModel($model)
 *   - a task without a configured model passes null (no override)
 *   - an empty-string config entry behaves like "no override"
 *   - the default chat() path is unaffected by task config
 */
class LlmServiceTaskRoutingTest extends TestCase
{
    public function test_configured_task_routes_to_override_model(): void
    {
        config(['services.llm.task_models' => [
            'classify' => 'google/gemini-2.5-flash',
        ]]);

        $provider = $this->fakeProvider();
        $service = new LlmService($provider);

        $service->chatFor(LlmService::TASK_CLASSIFY, 'system', [['role' => 'user', 'content' => 'hi']]);

        $this->assertSame(['google/gemini-2.5-flash'], $provider->withModelCalls);
        $this->assertSame(1, $provider->chatCalls);
    }

    public function test_unknown_task_falls_back_to_default_model(): void
    {
        config(['services.llm.task_models' => [
            'classify' => 'google/gemini-2.5-flash',
        ]]);

        $provider = $this->fakeProvider();
        $service = new LlmService($provider);

        $service->chatFor('unknown_task', 'system', []);

        $this->assertSame([null], $provider->withModelCalls);
    }

    public function test_empty_string_override_falls_back_to_default(): void
    {
        config(['services.llm.task_models' => [
            'reason' => '',
        ]]);

        $provider = $this->fakeProvider();
        $service = new LlmService($provider);

        $service->chatFor(LlmService::TASK_REASON, 'system', []);

        $this->assertSame([null], $provider->withModelCalls);
    }

    public function test_default_chat_bypasses_router(): void
    {
        config(['services.llm.task_models' => ['classify' => 'something/else']]);

        $provider = $this->fakeProvider();
        $service = new LlmService($provider);

        $service->chat('system', [['role' => 'user', 'content' => 'hi']]);

        $this->assertSame([], $provider->withModelCalls);
        $this->assertSame(1, $provider->chatCalls);
    }

    private function fakeProvider(): LlmProviderInterface
    {
        return new class implements LlmProviderInterface {
            public array $withModelCalls = [];
            public int $chatCalls = 0;

            public function chat(string $systemPrompt, array $messages): string
            {
                $this->chatCalls++;
                return 'ok';
            }

            public function name(): string
            {
                return 'fake';
            }

            public function withModel(?string $model): self
            {
                $this->withModelCalls[] = $model;
                return $this;
            }
        };
    }
}
