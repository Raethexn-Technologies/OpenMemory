<?php

namespace App\Providers;

use App\Services\ClusterDetectionService;
use App\Services\GraphExtractionService;
use App\Services\IcpMemoryService;
use App\Services\LLM\LlmProviderInterface;
use App\Services\LLM\LlmService;
use App\Services\LLM\OpenRouterProvider;
use App\Services\MemoryGraphService;
use App\Services\MemorySummarizationService;
use App\Services\MultiAgentGraphService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(LlmProviderInterface::class, function () {
            return new OpenRouterProvider(
                apiKey: config('services.llm.openrouter_api_key') ?? '',
                model: config('services.llm.openrouter_model', 'anthropic/claude-sonnet-4.5'),
                siteUrl: config('services.llm.openrouter_site_url', ''),
                siteName: config('services.llm.openrouter_site_name', 'OpenMemoryAgent'),
            );
        });

        $this->app->singleton(LlmService::class, function ($app) {
            return new LlmService($app->make(LlmProviderInterface::class));
        });

        $this->app->singleton(IcpMemoryService::class);

        $this->app->singleton(MemorySummarizationService::class, function ($app) {
            return new MemorySummarizationService($app->make(LlmService::class));
        });

        $this->app->singleton(GraphExtractionService::class, function ($app) {
            return new GraphExtractionService($app->make(LlmService::class));
        });

        $this->app->singleton(MemoryGraphService::class);

        $this->app->singleton(ClusterDetectionService::class);

        $this->app->singleton(MultiAgentGraphService::class, function ($app) {
            return new MultiAgentGraphService($app->make(MemoryGraphService::class));
        });
    }

    public function boot(): void
    {
        //
    }
}
