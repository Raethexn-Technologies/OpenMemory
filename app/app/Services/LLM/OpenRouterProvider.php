<?php

namespace App\Services\LLM;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * OpenRouter LLM provider.
 *
 * OpenRouter proxies 400+ models under a single OpenAI-compatible API.
 * Swap models at runtime via the OPENROUTER_MODEL env var — no code changes needed.
 *
 * Current model slugs (as of 2026 — verify latest at https://openrouter.ai/models):
 *
 *   FLAGSHIP
 *   anthropic/claude-sonnet-4.5       — Claude Sonnet 4.5 (fast, excellent default)
 *   anthropic/claude-opus-4.5         — Claude Opus 4.5 (most capable, higher cost)
 *   google/gemini-2.5-pro             — Gemini 2.5 Pro (strong reasoning)
 *   x-ai/grok-4-fast                  — Grok 4 Fast
 *   openai/gpt-5.2                    — GPT-5.2
 *
 *   FAST / CHEAP
 *   google/gemini-2.5-flash           — Gemini 2.5 Flash (very fast, cheap)
 *   google/gemini-2.5-flash-lite      — Gemini 2.5 Flash Lite (cheapest Gemini)
 *   openai/gpt-4o-mini                — GPT-4o Mini
 *
 *   FREE TIER (append :free for zero-cost, rate-limited access)
 *   google/gemini-2.5-flash:free
 *   google/gemini-2.5-pro-exp-03-25:free
 *   meta-llama/llama-4-scout:free
 *   meta-llama/llama-4-maverick:free
 *   meta-llama/llama-3.3-70b-instruct:free
 *   minimax/minimax-m2:free
 *
 * Configuration (.env):
 *   LLM_PROVIDER=openrouter
 *   OPENROUTER_API_KEY=sk-or-...        (get from https://openrouter.ai/keys)
 *   OPENROUTER_MODEL=anthropic/claude-sonnet-4.5   (optional, default shown)
 *   OPENROUTER_SITE_URL=https://your-app.com       (optional, improves OR dashboard tracking)
 *   OPENROUTER_SITE_NAME=OpenMemory           (optional)
 */
class OpenRouterProvider implements LlmProviderInterface
{
    private const API_URL = 'https://openrouter.ai/api/v1/chat/completions';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'anthropic/claude-sonnet-4.5',
        private readonly int $maxTokens = 1024,
        private readonly string $siteUrl = '',
        private readonly string $siteName = 'OpenMemory',
    ) {}

    public function chat(string $systemPrompt, array $messages): string
    {
        $allMessages = array_merge(
            [['role' => 'system', 'content' => $systemPrompt]],
            $messages
        );

        $headers = [
            'Authorization'    => 'Bearer ' . $this->apiKey,
            'Content-Type'     => 'application/json',
            'X-OpenRouter-Title' => $this->siteName,
        ];

        if ($this->siteUrl) {
            $headers['HTTP-Referer'] = $this->siteUrl;
        }

        $response = Http::withHeaders($headers)
            ->post(self::API_URL, [
                'model'      => $this->model,
                'max_tokens' => $this->maxTokens,
                'messages'   => $allMessages,
            ]);

        if ($response->failed()) {
            throw new RuntimeException(
                'OpenRouter API error (' . $response->status() . '): ' . $response->body()
            );
        }

        return $response->json('choices.0.message.content', '');
    }

    public function name(): string
    {
        // Include the model slug so the UI shows which model is active.
        return 'openrouter/' . $this->model;
    }

    public function withModel(?string $model): self
    {
        if ($model === null || $model === $this->model) {
            return $this;
        }

        return new self(
            apiKey:    $this->apiKey,
            model:     $model,
            maxTokens: $this->maxTokens,
            siteUrl:   $this->siteUrl,
            siteName:  $this->siteName,
        );
    }
}
