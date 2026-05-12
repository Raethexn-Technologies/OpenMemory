<?php

namespace App\Services;

use App\Models\RedactionPolicy;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Deterministic pre-LLM redaction for high-risk memory content.
 *
 * This service deliberately uses local pattern checks rather than an LLM. Its
 * job is not perfect entity recognition; it is a hard safety net for values
 * that should not cross model, canister, or graph boundaries as raw strings.
 */
class RedactionService
{
    private const ACTION_ALLOW = 'allow';
    private const ACTION_REDACT = 'redact';
    private const ACTION_TOKENIZE = 'tokenize';
    private const ACTION_ABSTRACT = 'abstract';

    /** @var array<string, string> */
    private const LABELS = [
        'payment_card' => 'PAYMENT_CARD',
        'payment_cvv' => 'PAYMENT_CVV',
        'bank_routing' => 'BANK_ROUTING',
        'bank_account' => 'BANK_ACCOUNT',
        'iban' => 'IBAN',
        'ssn' => 'SSN',
        'sin' => 'SIN',
        'credential' => 'CREDENTIAL',
        'private_key' => 'PRIVATE_KEY',
        'jwt' => 'JWT',
        'minor_age' => 'MINOR_AGE',
        'email' => 'EMAIL',
        'phone' => 'PHONE',
        'street_address' => 'STREET_ADDRESS',
        'date_of_birth' => 'DATE_OF_BIRTH',
        'compensation' => 'COMPENSATION',
        'health_condition' => 'HEALTH_CONDITION',
    ];

    /** @var array<string, int> */
    private const SENSITIVITY_RANK = [
        'public' => 0,
        'private' => 1,
        'sensitive' => 2,
    ];

    /** @var array<string, array{preset: string, rules: array<string, mixed>}|null> */
    private array $userPolicyCache = [];

    public function redact(
        string $text,
        ?string $userId = null,
        ?array $policy = null,
        ?string $preset = null,
    ): RedactionResult {
        if (! config('redaction.enabled', true) || $text === '') {
            return new RedactionResult($text, [], 'public', $preset ?? $this->defaultPresetName());
        }

        $policyName = $preset ?? $this->defaultPresetName();
        $rules = $this->resolvePolicy($policy, $policyName, $userId);
        $matches = [];

        $this->collectPrivateKeyMatches($matches, $text, $rules, $userId);
        $this->collectRegexMatches($matches, $text, 'jwt', '/\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\b/', $rules, $userId);
        $this->collectRegexMatches($matches, $text, 'credential', '/\bsk-(?:proj-)?[A-Za-z0-9_-]{20,}\b/', $rules, $userId);
        $this->collectRegexMatches($matches, $text, 'credential', '/\bgh[pousr]_[A-Za-z0-9_]{20,}\b/', $rules, $userId);
        $this->collectRegexMatches($matches, $text, 'credential', '/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/', $rules, $userId);
        $this->collectRegexMatches(
            $matches,
            $text,
            'credential',
            '/\b(?:password|passphrase|api[_ -]?key|secret|token|oauth[_ -]?token|access[_ -]?token)\b\s*(?:is|=|:)\s*["\']?([^"\'\s]{6,})/i',
            $rules,
            $userId,
            1,
        );

        $this->collectRegexMatches(
            $matches,
            $text,
            'payment_card',
            '/(?<!\d)(?:\d[ -]?){13,19}(?!\d)/',
            $rules,
            $userId,
            0,
            fn (string $value) => $this->digits($value),
            fn (string $value) => $this->isPaymentCard($value),
        );
        $this->collectRegexMatches($matches, $text, 'payment_cvv', '/\b(?:cvv|cvc|card security code|security code)\s*(?:is|=|:)?\s*(\d{3,4})\b/i', $rules, $userId, 1);
        $this->collectRegexMatches(
            $matches,
            $text,
            'bank_routing',
            '/\b(?:routing|aba)\s*(?:number|no\.?)?\s*(?:is|=|:)?\s*(\d{9})\b/i',
            $rules,
            $userId,
            1,
            fn (string $value) => $this->digits($value),
            fn (string $value) => $this->isAbaRoutingNumber($value),
        );
        $this->collectRegexMatches(
            $matches,
            $text,
            'bank_account',
            '/\b(?:account|acct|checking|savings)\s*(?:number|no\.?)?\s*(?:is|=|:)?\s*(\d{6,17})\b/i',
            $rules,
            $userId,
            1,
            fn (string $value) => $this->digits($value),
        );
        $this->collectRegexMatches(
            $matches,
            $text,
            'iban',
            '/\b[A-Z]{2}\d{2}[A-Z0-9]{11,30}\b/i',
            $rules,
            $userId,
            0,
            fn (string $value) => strtoupper(str_replace(' ', '', $value)),
            fn (string $value) => $this->isIban($value),
        );
        $this->collectRegexMatches($matches, $text, 'ssn', '/\b(?!000|666|9\d\d)\d{3}[- ]?(?!00)\d{2}[- ]?(?!0000)\d{4}\b/', $rules, $userId);
        $this->collectRegexMatches(
            $matches,
            $text,
            'sin',
            '/\b(?:sin|social insurance number)\s*(?:is|=|:)?\s*(\d{3}[- ]?\d{3}[- ]?\d{3})\b/i',
            $rules,
            $userId,
            1,
            fn (string $value) => $this->digits($value),
            fn (string $value) => $this->isCanadianSin($value),
        );
        $this->collectRegexMatches($matches, $text, 'email', '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $rules, $userId);
        $this->collectRegexMatches($matches, $text, 'phone', '/(?<!\d)(?:\+?1[\s.-]?)?(?:\(\d{3}\)|\d{3})[\s.-]?\d{3}[\s.-]?\d{4}(?!\d)/', $rules, $userId);
        $this->collectRegexMatches(
            $matches,
            $text,
            'date_of_birth',
            '/\b(?:dob|date of birth|born|birthday)\s*(?:is|=|:|on)?\s*((?:\d{1,2}[\/\-.]\d{1,2}[\/\-.]\d{2,4})|(?:[A-Z][a-z]+ \d{1,2},? \d{4})|(?:\d{4}-\d{2}-\d{2}))\b/i',
            $rules,
            $userId,
            1,
        );
        $this->collectRegexMatches(
            $matches,
            $text,
            'street_address',
            '/\b\d{1,6}\s+(?:[A-Za-z0-9\'.-]+\s+){1,6}(?:Street|St\.?|Avenue|Ave\.?|Road|Rd\.?|Boulevard|Blvd\.?|Lane|Ln\.?|Drive|Dr\.?|Court|Ct\.?|Way|Place|Pl\.?)\b(?:,\s*[A-Za-z .\'-]+)?(?:,\s*[A-Z]{2})?(?:\s+\d{5}(?:-\d{4})?)?/i',
            $rules,
            $userId,
        );
        $this->collectRegexMatches(
            $matches,
            $text,
            'compensation',
            '/\b(?:salary|compensation|income|base pay|bonus|pay)\s*(?:is|=|:)?\s*(\$?\d[\d,]*(?:\.\d+)?\s*(?:k|K|m|M)?(?:\s*(?:per year|annually|\/yr|a year))?)\b/i',
            $rules,
            $userId,
            1,
        );
        $this->collectRegexMatches(
            $matches,
            $text,
            'health_condition',
            '/\b(?:diagnosed with|medical condition is|prescription is|taking medication)\s+([A-Za-z][A-Za-z -]{2,40})\b/i',
            $rules,
            $userId,
            1,
        );
        $this->collectRegexMatches(
            $matches,
            $text,
            'minor_age',
            '/\b(?:my\s+(?:child|son|daughter|kid)|minor)\s+(?:[A-Z][a-z]+\s+)?(?:is\s+)?([1-9]|1[0-7])\b/i',
            $rules,
            $userId,
            1,
        );

        $selected = $this->selectNonOverlapping($matches);

        if ($selected === []) {
            return new RedactionResult($text, [], 'public', $policyName);
        }

        $redacted = $this->applyMatches($text, $selected);
        $findings = array_map(
            static fn (array $match) => [
                'category' => $match['category'],
                'action' => $match['action'],
                'sensitivity' => $match['sensitivity'],
            ],
            $selected,
        );

        return new RedactionResult(
            text: $redacted,
            findings: $findings,
            minimumSensitivity: $this->highestSensitivity(array_column($findings, 'sensitivity')),
            policy: $policyName,
        );
    }

    public function enforceSensitivity(string $current, RedactionResult ...$results): string
    {
        $sensitivities = [$current];

        foreach ($results as $result) {
            $sensitivities[] = $result->minimumSensitivity;
        }

        return $this->highestSensitivity($sensitivities);
    }

    /**
     * @return array{applied: bool, policy: string, categories: array<int, string>, counts: array<string, int>, minimum_sensitivity: string}|null
     */
    public function metadata(RedactionResult ...$results): ?array
    {
        $counts = [];
        $policies = [];
        $sensitivities = ['public'];

        foreach ($results as $result) {
            if (! $result->applied()) {
                continue;
            }

            $policies[] = $result->policy;
            $sensitivities[] = $result->minimumSensitivity;

            foreach ($result->counts() as $category => $count) {
                $counts[$category] = ($counts[$category] ?? 0) + $count;
            }
        }

        if ($counts === []) {
            return null;
        }

        ksort($counts);

        return [
            'applied' => true,
            'policy' => implode(',', array_values(array_unique($policies))),
            'categories' => array_keys($counts),
            'counts' => $counts,
            'minimum_sensitivity' => $this->highestSensitivity($sensitivities),
        ];
    }

    /**
     * @return array<string, array{action: string, sensitivity: string}>
     */
    private function resolvePolicy(?array $policy, string &$preset, ?string $userId): array
    {
        $userPolicy = $this->userPolicy($userId);

        if ($userPolicy !== null) {
            $preset = $userPolicy['preset'];
            $policy = array_replace_recursive($userPolicy['rules'], $policy ?? []);
        }

        $presetRules = config("redaction.presets.{$preset}", config('redaction.presets.personal', []));
        $floorRules = config('redaction.floor', []);

        return array_replace_recursive($presetRules, $policy ?? [], $floorRules);
    }

    /**
     * @return array{preset: string, rules: array<string, mixed>}|null
     */
    private function userPolicy(?string $userId): ?array
    {
        if (! $userId) {
            return null;
        }

        if (array_key_exists($userId, $this->userPolicyCache)) {
            return $this->userPolicyCache[$userId];
        }

        try {
            if (! Schema::hasTable('redaction_policies')) {
                return $this->userPolicyCache[$userId] = null;
            }

            $policy = RedactionPolicy::where('user_id', $userId)->first();
            if (! $policy) {
                return $this->userPolicyCache[$userId] = null;
            }

            return $this->userPolicyCache[$userId] = [
                'preset' => $policy->preset ?: $this->defaultPresetName(),
                'rules' => $policy->rules ?? [],
            ];
        } catch (Throwable) {
            return $this->userPolicyCache[$userId] = null;
        }
    }

    private function defaultPresetName(): string
    {
        return (string) config('redaction.default_preset', 'personal');
    }

    /**
     * @param  array<int, array<string, mixed>>  $matches
     * @param  array<string, array{action: string, sensitivity: string}>  $rules
     */
    private function collectRegexMatches(
        array &$matches,
        string $text,
        string $category,
        string $pattern,
        array $rules,
        ?string $userId,
        int $group = 0,
        ?callable $normalizer = null,
        ?callable $validator = null,
    ): void {
        $rule = $rules[$category] ?? ['action' => self::ACTION_ALLOW, 'sensitivity' => 'public'];
        if (($rule['action'] ?? self::ACTION_ALLOW) === self::ACTION_ALLOW) {
            return;
        }

        if (! preg_match_all($pattern, $text, $found, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return;
        }

        foreach ($found as $match) {
            if (! isset($match[$group])) {
                continue;
            }

            $value = $match[$group][0];
            $offset = $match[$group][1];
            $normalized = $normalizer ? $normalizer($value) : $this->normalizeValue($category, $value);

            if ($validator && ! $validator($value)) {
                continue;
            }

            $matches[] = $this->makeMatch($category, $value, $normalized, $offset, $rule, $userId);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $matches
     * @param  array<string, array{action: string, sensitivity: string}>  $rules
     */
    private function collectPrivateKeyMatches(array &$matches, string $text, array $rules, ?string $userId): void
    {
        $category = 'private_key';
        $rule = $rules[$category] ?? ['action' => self::ACTION_REDACT, 'sensitivity' => 'sensitive'];

        if (($rule['action'] ?? self::ACTION_REDACT) === self::ACTION_ALLOW) {
            return;
        }

        if (! preg_match_all('/-----BEGIN [A-Z ]*PRIVATE KEY-----[\s\S]+?-----END [A-Z ]*PRIVATE KEY-----/', $text, $found, PREG_OFFSET_CAPTURE)) {
            return;
        }

        foreach ($found[0] as [$value, $offset]) {
            $matches[] = $this->makeMatch($category, $value, 'private-key', $offset, $rule, $userId);
        }
    }

    /**
     * @param  array{action: string, sensitivity: string}  $rule
     * @return array<string, mixed>
     */
    private function makeMatch(
        string $category,
        string $value,
        string $normalized,
        int $offset,
        array $rule,
        ?string $userId,
    ): array {
        $action = $rule['action'] ?? self::ACTION_REDACT;

        return [
            'category' => $category,
            'action' => $action,
            'sensitivity' => $rule['sensitivity'] ?? 'private',
            'start' => $offset,
            'length' => strlen($value),
            'replacement' => $this->replacementFor($category, $action, $normalized, $userId),
        ];
    }

    private function replacementFor(string $category, string $action, string $normalized, ?string $userId): string
    {
        $label = self::LABELS[$category] ?? strtoupper($category);

        return match ($action) {
            self::ACTION_TOKENIZE => sprintf('[%s#%s]', $label, $this->token($category, $normalized, $userId)),
            self::ACTION_ABSTRACT => $this->abstractReplacement($category, $normalized),
            default => sprintf('[%s]', $label),
        };
    }

    private function abstractReplacement(string $category, string $normalized): string
    {
        if ($category !== 'compensation') {
            return sprintf('[%s]', self::LABELS[$category] ?? strtoupper($category));
        }

        $amount = $this->parseMoneyAmount($normalized);

        if ($amount === null) {
            return '[COMPENSATION]';
        }

        return match (true) {
            $amount < 50000 => '[COMPENSATION:UNDER_50K]',
            $amount < 100000 => '[COMPENSATION:50K_100K]',
            $amount < 200000 => '[COMPENSATION:100K_200K]',
            default => '[COMPENSATION:OVER_200K]',
        };
    }

    private function token(string $category, string $normalized, ?string $userId): string
    {
        $key = (string) config('redaction.hash_key', config('app.key', 'openmemory-redaction-dev-key'));

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            if ($decoded !== false) {
                $key = $decoded;
            }
        }

        return substr(hash_hmac('sha256', "{$category}|{$userId}|{$normalized}", $key), 0, 12);
    }

    /**
     * @param  array<int, array<string, mixed>>  $matches
     * @return array<int, array<string, mixed>>
     */
    private function selectNonOverlapping(array $matches): array
    {
        usort($matches, static function (array $a, array $b): int {
            if ($a['start'] !== $b['start']) {
                return $a['start'] <=> $b['start'];
            }

            return $b['length'] <=> $a['length'];
        });

        $selected = [];
        $coveredUntil = -1;

        foreach ($matches as $match) {
            if ($match['start'] < $coveredUntil) {
                continue;
            }

            $selected[] = $match;
            $coveredUntil = $match['start'] + $match['length'];
        }

        return $selected;
    }

    /**
     * @param  array<int, array<string, mixed>>  $matches
     */
    private function applyMatches(string $text, array $matches): string
    {
        usort($matches, static fn (array $a, array $b) => $b['start'] <=> $a['start']);

        foreach ($matches as $match) {
            $text = substr_replace($text, $match['replacement'], $match['start'], $match['length']);
        }

        return $text;
    }

    /**
     * @param  array<int, string|null>  $sensitivities
     */
    private function highestSensitivity(array $sensitivities): string
    {
        $highest = 'public';

        foreach ($sensitivities as $sensitivity) {
            $sensitivity = $sensitivity ?: 'public';
            if ((self::SENSITIVITY_RANK[$sensitivity] ?? 0) > self::SENSITIVITY_RANK[$highest]) {
                $highest = $sensitivity;
            }
        }

        return $highest;
    }

    private function normalizeValue(string $category, string $value): string
    {
        if (in_array($category, ['payment_card', 'payment_cvv', 'bank_routing', 'bank_account', 'ssn', 'sin', 'phone'], true)) {
            return $this->digits($value);
        }

        return mb_strtolower(trim($value));
    }

    private function digits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    private function isPaymentCard(string $value): bool
    {
        $digits = $this->digits($value);
        $length = strlen($digits);

        return $length >= 13 && $length <= 19 && $this->luhn($digits);
    }

    private function isAbaRoutingNumber(string $value): bool
    {
        $digits = $this->digits($value);
        if (strlen($digits) !== 9) {
            return false;
        }

        $checksum = 3 * ((int) $digits[0] + (int) $digits[3] + (int) $digits[6])
            + 7 * ((int) $digits[1] + (int) $digits[4] + (int) $digits[7])
            + ((int) $digits[2] + (int) $digits[5] + (int) $digits[8]);

        return $checksum > 0 && $checksum % 10 === 0;
    }

    private function isCanadianSin(string $value): bool
    {
        $digits = $this->digits($value);

        return strlen($digits) === 9 && $this->luhn($digits);
    }

    private function isIban(string $value): bool
    {
        $iban = strtoupper(str_replace(' ', '', $value));
        if (! preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban)) {
            return false;
        }

        $rearranged = substr($iban, 4) . substr($iban, 0, 4);
        $numeric = '';

        foreach (str_split($rearranged) as $char) {
            $numeric .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }

        $remainder = 0;
        foreach (str_split($numeric) as $digit) {
            $remainder = ($remainder * 10 + (int) $digit) % 97;
        }

        return $remainder === 1;
    }

    private function luhn(string $digits): bool
    {
        $sum = 0;
        $alternate = false;

        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $n = (int) $digits[$i];

            if ($alternate) {
                $n *= 2;
                if ($n > 9) {
                    $n -= 9;
                }
            }

            $sum += $n;
            $alternate = ! $alternate;
        }

        return $sum > 0 && $sum % 10 === 0;
    }

    private function parseMoneyAmount(string $value): ?float
    {
        if (! preg_match('/(\d[\d,]*(?:\.\d+)?)\s*([kKmM])?/', $value, $m)) {
            return null;
        }

        $amount = (float) str_replace(',', '', $m[1]);
        $suffix = strtolower($m[2] ?? '');

        return match ($suffix) {
            'k' => $amount * 1000,
            'm' => $amount * 1000000,
            default => $amount,
        };
    }
}
