<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Validates every benchmark corpus fixture so a malformed corpus fails CI
 * instead of failing silently mid-run or skewing a paid benchmark pass.
 */
class BenchmarkCorpusValidationTest extends TestCase
{
    /**
     * @return array<int, string>
     */
    private function corpusPaths(): array
    {
        $paths = glob(base_path('database/benchmarks/corpus_*.json')) ?: [];
        $this->assertNotEmpty($paths, 'No benchmark corpora found.');

        return $paths;
    }

    public function test_all_corpora_are_valid_json_with_required_fields(): void
    {
        foreach ($this->corpusPaths() as $path) {
            $name = basename($path);
            $corpus = json_decode((string) file_get_contents($path), true);

            $this->assertIsArray($corpus, "{$name} is not valid JSON.");
            $this->assertArrayHasKey('id', $corpus, "{$name} is missing an id.");
            $this->assertArrayHasKey('description', $corpus, "{$name} is missing a description.");
            $this->assertNotEmpty($corpus['memories'] ?? [], "{$name} has no memories.");
            $this->assertNotEmpty($corpus['questions'] ?? [], "{$name} has no questions.");
            $this->assertArrayHasKey('goals', $corpus, "{$name} is missing a goals array.");

            foreach ($corpus['memories'] as $i => $memory) {
                $this->assertNotSame('', trim($memory['content'] ?? ''), "{$name} memory {$i} has no content.");
                $this->assertIsInt($memory['created_days_ago'] ?? null, "{$name} memory {$i} has no created_days_ago.");
                $this->assertIsArray($memory['tags'] ?? null, "{$name} memory {$i} has no tags array.");
            }

            foreach ($corpus['goals'] as $i => $goal) {
                $this->assertNotSame('', trim($goal['content'] ?? ''), "{$name} goal {$i} has no content.");
                $this->assertNotSame('', trim($goal['label'] ?? ''), "{$name} goal {$i} has no label.");
            }

            $seenIds = [];
            foreach ($corpus['questions'] as $i => $question) {
                $this->assertNotSame('', trim($question['id'] ?? ''), "{$name} question {$i} has no id.");
                $this->assertNotSame('', trim($question['question'] ?? ''), "{$name} question {$i} has no question text.");
                $this->assertNotEmpty($question['expected_themes'] ?? [], "{$name} question {$i} has no expected themes.");
                $this->assertNotContains($question['id'], $seenIds, "{$name} has a duplicate question id {$question['id']}.");
                $seenIds[] = $question['id'];
            }
        }
    }

    public function test_corpora_contain_no_sensitive_seed_records(): void
    {
        // Benchmark corpora are seeded as public knowledge and their retrieved
        // content is written into benchmark reports. A corpus record marked
        // private or sensitive would signal a fixture authoring mistake.
        foreach ($this->corpusPaths() as $path) {
            $corpus = json_decode((string) file_get_contents($path), true);

            foreach ($corpus['memories'] as $i => $memory) {
                $this->assertSame(
                    'public',
                    $memory['sensitivity'] ?? 'public',
                    basename($path)." memory {$i} declares non-public sensitivity.",
                );
            }
        }
    }

    public function test_classed_corpora_use_known_question_classes(): void
    {
        $known = [
            'planning', 'historical_decision', 'cross_topic_synthesis',
            'contradiction_resolution', 'recent_status', 'durable_preference',
            'insufficient_evidence',
        ];

        foreach ($this->corpusPaths() as $path) {
            $corpus = json_decode((string) file_get_contents($path), true);

            foreach ($corpus['questions'] as $question) {
                if (! isset($question['class'])) {
                    continue;
                }

                $this->assertContains(
                    $question['class'],
                    $known,
                    basename($path)." question {$question['id']} uses unknown class {$question['class']}.",
                );
            }
        }
    }
}
