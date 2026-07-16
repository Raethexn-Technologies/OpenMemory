<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // LLM — OpenRouter proxies 400+ models under one API key (openrouter.ai)
    //
    // task_models routes specific cognitive workloads to cheaper or stronger
    // models than the default. Anything not listed here uses OPENROUTER_MODEL.
    // Leaving an entry empty falls through to the default — no special-case
    // logic needed on the caller side.
    //
    //   classify  — sensitivity tagging, short label decisions: cheap & fast.
    //   summarize — single-item memory extraction: mid-tier is plenty.
    //   reason    — graph extraction, consolidation: spend on the best model.
    //   chat      — user-facing replies. Leave empty to use the default.
    'llm' => [
        'openrouter_api_key' => env('OPENROUTER_API_KEY'),
        'openrouter_model' => env('OPENROUTER_MODEL', 'anthropic/claude-sonnet-4.5'),
        'openrouter_site_url' => env('OPENROUTER_SITE_URL', ''),
        'openrouter_site_name' => env('OPENROUTER_SITE_NAME', 'OpenMemory'),
        'task_models' => [
            'classify' => env('LLM_MODEL_CLASSIFY', 'google/gemini-2.5-flash'),
            'summarize' => env('LLM_MODEL_SUMMARIZE', 'google/gemini-2.5-flash'),
            'reason' => env('LLM_MODEL_REASON', ''),
            'chat' => env('LLM_MODEL_CHAT', ''),
        ],
    ],

    // MCP server write endpoint — shared secret for X-OMA-API-Key auth
    'mcp' => [
        'api_key' => env('MCP_API_KEY', ''),
    ],

    // GitHub ingest. A token is optional for public repos but raises the
    // rate limit from 60 to 5000 requests/hour and is required for private
    // repos. Create one at https://github.com/settings/tokens (read:repo scope).
    'github' => [
        'token' => env('GITHUB_INGEST_TOKEN', ''),
    ],

    // Auto-ingest: continuous accumulation from connected sources.
    //
    // schedule_enabled — when true, the Laravel scheduler runs ingest:github
    //                    at the configured cadence. Off by default so the
    //                    feature stays demo-able via the UI button alone;
    //                    flip it on once a token + repo list are configured.
    // repos            — comma-separated owner/repo slugs to sweep.
    // per_repo_limit   — max commits to inspect per repo per run.
    'ingest' => [
        'schedule_enabled' => env('INGEST_SCHEDULE_ENABLED', false),
        'repos' => env('INGEST_GITHUB_REPOS', ''),
        'per_repo_limit' => (int) env('INGEST_PER_REPO_LIMIT', 20),
    ],

    // Memory retrieval strategy for the chat path. One of the strategies in
    // MemoryGraphService::STRATEGIES. goal_graph preserves the pre-existing
    // behaviour; query_lexical returns direct lexical matches; query_graph and
    // hybrid_query_graph seed graph traversal from the current redacted user
    // message using deterministic lexical scoring.
    'retrieval' => [
        'strategy' => env('RETRIEVAL_STRATEGY', 'goal_graph'),
    ],

    // Corpus-grounded document QA. When enabled, chat responses are built from
    // evidence_facts selected from graph-retrieved public document chunks. If
    // no evidence facts match the query, the assistant is instructed to refuse.
    'grounded' => [
        'enabled' => env('GROUNDED_RETRIEVAL', false),
        'evidence_limit' => (int) env('GROUNDED_EVIDENCE_LIMIT', 8),
    ],

    // ICP Memory Canister
    'icp' => [
        'endpoint' => env('ICP_CANISTER_ENDPOINT', 'http://localhost:4943'),
        'canister_id' => env('ICP_CANISTER_ID', ''),
        'mock' => env('ICP_MOCK_MODE', true),
        // ICP_BROWSER_HOST: the URL the user's browser uses to reach the dfx replica or
        // ICP mainnet gateway. Separate from ICP_CANISTER_ENDPOINT (Laravel→adapter).
        // Local default: http://localhost:4943  |  Mainnet: https://ic0.app
        'browser_host' => env('ICP_BROWSER_HOST', 'http://localhost:4943'),
        // ICP_II_PROVIDER_URL: the Internet Identity provider URL the browser
        // AuthClient connects to. Leaving this empty disables II login and
        // blocks live ICP writes (mock mode still works without it).
        // Local dfx II: http://<II_CANISTER_ID>.localhost:4943 after `dfx deps deploy internet_identity`.
        // Mainnet II:   https://identity.ic0.app
        'ii_provider_url' => env('ICP_II_PROVIDER_URL', ''),
    ],

];
