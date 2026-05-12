<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Memory Redaction
    |--------------------------------------------------------------------------
    |
    | The redaction layer runs before text crosses LLM or storage boundaries.
    | "floor" categories are non-negotiable: user policy cannot downgrade them
    | to allow. Presets control the categories that are useful to tune by user,
    | organization, or deployment environment.
    |
    | Supported actions:
    |   allow     - leave the value unchanged
    |   redact    - replace with a stable category placeholder
    |   tokenize  - replace with a category placeholder plus deterministic HMAC
    |   abstract  - replace with a lower-resolution category, when supported
    */

    'enabled' => env('REDACTION_ENABLED', true),

    'hash_key' => env('REDACTION_HASH_KEY', env('APP_KEY', 'openmemory-redaction-dev-key')),

    'default_preset' => env('REDACTION_PRESET', 'personal'),

    'floor' => [
        'payment_card' => ['action' => 'tokenize', 'sensitivity' => 'sensitive'],
        'payment_cvv' => ['action' => 'redact', 'sensitivity' => 'sensitive'],
        'bank_routing' => ['action' => 'tokenize', 'sensitivity' => 'sensitive'],
        'bank_account' => ['action' => 'tokenize', 'sensitivity' => 'sensitive'],
        'iban' => ['action' => 'tokenize', 'sensitivity' => 'sensitive'],
        'ssn' => ['action' => 'tokenize', 'sensitivity' => 'sensitive'],
        'sin' => ['action' => 'tokenize', 'sensitivity' => 'sensitive'],
        'credential' => ['action' => 'redact', 'sensitivity' => 'sensitive'],
        'private_key' => ['action' => 'redact', 'sensitivity' => 'sensitive'],
        'jwt' => ['action' => 'redact', 'sensitivity' => 'sensitive'],
        'minor_age' => ['action' => 'redact', 'sensitivity' => 'sensitive'],
    ],

    'presets' => [
        'personal' => [
            'email' => ['action' => 'allow', 'sensitivity' => 'private'],
            'phone' => ['action' => 'tokenize', 'sensitivity' => 'private'],
            'street_address' => ['action' => 'redact', 'sensitivity' => 'private'],
            'date_of_birth' => ['action' => 'redact', 'sensitivity' => 'sensitive'],
            'compensation' => ['action' => 'abstract', 'sensitivity' => 'sensitive'],
            'health_condition' => ['action' => 'redact', 'sensitivity' => 'sensitive'],
        ],

        'professional' => [
            'email' => ['action' => 'allow', 'sensitivity' => 'private'],
            'phone' => ['action' => 'redact', 'sensitivity' => 'private'],
            'street_address' => ['action' => 'redact', 'sensitivity' => 'private'],
            'date_of_birth' => ['action' => 'redact', 'sensitivity' => 'sensitive'],
            'compensation' => ['action' => 'abstract', 'sensitivity' => 'sensitive'],
            'health_condition' => ['action' => 'redact', 'sensitivity' => 'sensitive'],
        ],

        'regulated' => [
            'email' => ['action' => 'tokenize', 'sensitivity' => 'private'],
            'phone' => ['action' => 'tokenize', 'sensitivity' => 'private'],
            'street_address' => ['action' => 'redact', 'sensitivity' => 'private'],
            'date_of_birth' => ['action' => 'redact', 'sensitivity' => 'sensitive'],
            'compensation' => ['action' => 'abstract', 'sensitivity' => 'sensitive'],
            'health_condition' => ['action' => 'redact', 'sensitivity' => 'sensitive'],
        ],
    ],
];
