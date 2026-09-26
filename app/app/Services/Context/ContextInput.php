<?php

namespace App\Services\Context;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ContextInput
{
    public static function request(\Illuminate\Http\Request $request): array
    {
        try {
            $shape = json_decode($request->getContent(), false, 16, JSON_THROW_ON_ERROR);
            if (! $shape instanceof \stdClass) {
                throw new \JsonException;
            }
            foreach (['sources', 'capabilities', 'source_resources', 'repositories', 'query_disclosures'] as $field) {
                if (property_exists($shape, $field) && ! is_array($shape->{$field})) {
                    throw new \JsonException;
                }
            }

            return json_decode($request->getContent(), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::withMessages(['context' => 'Invalid context JSON body.']);
        }
    }

    public static function validate(array $input, array $rules): array
    {
        if (array_diff(array_keys($input), array_filter(array_keys($rules), fn ($key) => ! str_contains($key, '.'))) !== []
            || Validator::make($input, $rules)->fails()) {
            throw ValidationException::withMessages(['context' => 'Invalid context request or application settings.']);
        }

        return $input;
    }
}
