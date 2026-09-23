<?php

namespace App\Services\NativeMemory;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class MemoryInput
{
    public static function validate(array $input, array $rules): array
    {
        // Error messages never contain submitted values or arbitrary field names.
        if (array_diff(array_keys($input), array_keys($rules)) !== []) {
            self::invalid();
        }
        $validator = Validator::make($input, $rules);
        if ($validator->fails()) {
            self::invalid();
        }

        return $validator->validated();
    }

    public static function invalid(): never
    {
        throw ValidationException::withMessages(['memory' => 'Invalid native-memory input. Check the documented format.']);
    }

    public static function content(string $content): void
    {
        if (trim($content) === '' || strlen($content) > 8000 || str_contains($content, "\0")) {
            self::invalid();
        }

        $inspection = app(\App\Services\RedactionService::class)->redact($content, force: true);
        foreach ($inspection->findings as $finding) {
            if (array_key_exists($finding['category'], config('redaction.floor', []))) {
                self::invalid();
            }
        }

        // This local safeguard recognizes common credential forms, not every secret.
        $patterns = [
            '/-----BEGIN (?:[A-Z ]*PRIVATE KEY|OPENSSH PRIVATE KEY)-----/',
            '/\\b(?:sk-(?:proj-)?|gh[pousr]_|github_pat_)[A-Za-z0-9_-]{20,}\\b/',
            '/\\b(?:AKIA|ASIA)[A-Z0-9]{16}\\b/',
            '/\\beyJ[A-Za-z0-9_-]{10,}\\.[A-Za-z0-9_-]{10,}\\.[A-Za-z0-9_-]{10,}\\b/',
            '/\\b(?:password|passphrase|token|session[_ -]?secret|api[_ -]?key|secret|(?:access|refresh|session|oauth)[_ -]?token|client[_ -]?secret)\\b["\\\']?\\s*(?:is|=|:)\\s*["\\\']?[^\\s"\\\']+/i',
            '/\\bBearer\\s+[^\\s]+/i',
            '~https?://[^\\s/@]+:[^\\s/@]+@~i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                self::invalid();
            }
        }
    }

    public static function revision(mixed $value): void
    {
        if (! is_int($value) || $value < 1 || $value > 2147483647) {
            self::invalid();
        }
    }
}
