<?php

namespace App\Services\LLM;

interface LlmProviderInterface
{
    /**
     * Generate a chat response given a system prompt and messages array.
     *
     * @param  string  $systemPrompt
     * @param  array   $messages  [['role' => 'user'|'assistant', 'content' => string], ...]
     * @return string
     */
    public function chat(string $systemPrompt, array $messages): string;

    /**
     * Return a short identifier for this provider.
     */
    public function name(): string;

    /**
     * Return a clone of this provider configured to use a different model.
     *
     * Used by LlmService when routing a task (classify, summarize, reason)
     * to a model other than the default. Passing null returns the provider
     * unchanged so callers do not have to special-case "no override".
     */
    public function withModel(?string $model): self;
}
