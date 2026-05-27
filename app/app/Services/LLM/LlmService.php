<?php

namespace App\Services\LLM;

class LlmService
{
    /**
     * Known task tags. Callers pass one of these to chatFor() so config can
     * route the call to a model whose cost/capability matches the workload.
     *
     *   classify  — short tag/label decisions, sensitivity classification.
     *               Wants a fast cheap model; high latency hurts UX more
     *               than a small accuracy regression.
     *   summarize — compressing a chunk or turn into a durable memory.
     *               Wants competence but does not need flagship reasoning.
     *   reason    — multi-step thinking (graph extraction, consolidation).
     *               Worth spending on the strongest model available.
     *   chat      — the user-facing conversational reply. Default model.
     */
    public const TASK_CLASSIFY  = 'classify';
    public const TASK_SUMMARIZE = 'summarize';
    public const TASK_REASON    = 'reason';
    public const TASK_CHAT      = 'chat';

    public function __construct(
        private readonly LlmProviderInterface $provider,
    ) {}

    public function chat(string $systemPrompt, array $messages): string
    {
        return $this->provider->chat($systemPrompt, $messages);
    }

    /**
     * Run a chat request under a specific task tag so config can route it to
     * a cheaper or stronger model than the default. Missing or unknown task
     * tags fall back to the default provider — callers never have to know
     * whether a route is configured for their task.
     */
    public function chatFor(string $task, string $systemPrompt, array $messages): string
    {
        $model = $this->modelForTask($task);

        return $this->provider->withModel($model)->chat($systemPrompt, $messages);
    }

    /**
     * Look up the configured model override for a task, or null if none.
     *
     * Config shape (services.llm.task_models):
     *   ['classify' => 'google/gemini-2.5-flash', 'reason' => 'anthropic/claude-opus-4.5']
     *
     * Tasks without an entry use the default model the provider was built with.
     */
    private function modelForTask(string $task): ?string
    {
        $overrides = config('services.llm.task_models', []);
        if (! is_array($overrides)) {
            return null;
        }

        $model = $overrides[$task] ?? null;
        return is_string($model) && $model !== '' ? $model : null;
    }

    public function provider(): string
    {
        return $this->provider->name();
    }

    /**
     * Build the agent system prompt with optional injected memory.
     */
    public function buildSystemPrompt(array $memories = []): string
    {
        $base = <<<PROMPT
You are a helpful AI assistant with persistent memory. You remember facts about users across conversations.

When a user shares information about themselves, acknowledge it naturally and let them know you will remember it.
Keep responses conversational, concise, and helpful.
PROMPT;

        if (empty($memories)) {
            return $base;
        }

        $memoryBlock = implode("\n", array_map(
            fn($m) => "- {$m['content']} (stored: {$m['timestamp']})",
            $memories
        ));

        return $base . "\n\n## What you remember about this user:\n{$memoryBlock}\n\nUse this context naturally in your responses.";
    }
}
