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

    // LLM Provider config — swap via LLM_PROVIDER env var
    'llm' => [
        'provider'        => env('LLM_PROVIDER', 'claude'),
        'claude_api_key'  => env('CLAUDE_API_KEY'),
        'claude_model'    => env('CLAUDE_MODEL', 'claude-sonnet-4-6'),
        'gemini_api_key'  => env('GEMINI_API_KEY'),
        'gemini_model'    => env('GEMINI_MODEL', 'gemini-1.5-flash'),
        'openai_api_key'  => env('OPENAI_API_KEY'),
        'openai_model'    => env('OPENAI_MODEL', 'gpt-4o-mini'),
    ],

    // ICP Memory Canister
    'icp' => [
        'endpoint'     => env('ICP_CANISTER_ENDPOINT', 'http://localhost:4943'),
        'canister_id'  => env('ICP_CANISTER_ID', ''),
        'mock'         => env('ICP_MOCK_MODE', true),
        // ICP_BROWSER_HOST: the URL the user's browser uses to reach the dfx replica or
        // ICP mainnet gateway. Separate from ICP_CANISTER_ENDPOINT (Laravel→adapter).
        // Local default: http://localhost:4943  |  Mainnet: https://ic0.app
        'browser_host' => env('ICP_BROWSER_HOST', 'http://localhost:4943'),
    ],

];
