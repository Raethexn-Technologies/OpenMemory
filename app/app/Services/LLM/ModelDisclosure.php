<?php

namespace App\Services\LLM;

use Illuminate\Auth\Access\AuthorizationException;

final class ModelDisclosure
{
    public static function allows(string $operation): bool
    {
        return in_array($operation, [
            'chat', 'history_ask', 'public_extraction', 'document_processing',
            'ingestion', 'consolidation', 'benchmark',
        ], true) && in_array($operation, config('disclosure.model_operations', []), true);
    }

    public static function authorize(string $operation): void
    {
        if (! self::allows($operation)) {
            throw new AuthorizationException('Model disclosure is not authorized for this operation.');
        }
    }
}
