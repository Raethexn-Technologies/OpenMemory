<?php

namespace App\Services;

class RedactionResult
{
    /**
     * @param  array<int, array{category: string, action: string, sensitivity: string}>  $findings
     */
    public function __construct(
        public readonly string $text,
        public readonly array $findings = [],
        public readonly string $minimumSensitivity = 'public',
        public readonly string $policy = 'default',
    ) {}

    public function changed(string $original): bool
    {
        return $this->text !== $original;
    }

    public function applied(): bool
    {
        return $this->findings !== [];
    }

    /**
     * @return array<int, string>
     */
    public function categories(): array
    {
        return array_values(array_unique(array_map(
            static fn (array $finding) => $finding['category'],
            $this->findings,
        )));
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = [];

        foreach ($this->findings as $finding) {
            $category = $finding['category'];
            $counts[$category] = ($counts[$category] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    /**
     * @return array{applied: bool, policy: string, categories: array<int, string>, counts: array<string, int>, minimum_sensitivity: string}
     */
    public function toMetadata(): array
    {
        return [
            'applied' => $this->applied(),
            'policy' => $this->policy,
            'categories' => $this->categories(),
            'counts' => $this->counts(),
            'minimum_sensitivity' => $this->minimumSensitivity,
        ];
    }
}
