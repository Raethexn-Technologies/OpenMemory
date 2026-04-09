<?php

namespace Tests\Unit;

use App\Services\DocumentChunkerService;
use Tests\TestCase;

class DocumentChunkerServiceTest extends TestCase
{
    private DocumentChunkerService $chunker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->chunker = new DocumentChunkerService();
    }

    public function test_empty_string_returns_no_chunks(): void
    {
        $this->assertSame([], $this->chunker->chunk(''));
    }

    public function test_text_below_minimum_length_returns_no_chunks(): void
    {
        $this->assertSame([], $this->chunker->chunk('Too short.'));
    }

    public function test_single_paragraph_within_limit_returns_one_chunk(): void
    {
        $text = 'This is a single paragraph that contains enough content to pass the minimum length threshold.';
        $chunks = $this->chunker->chunk($text);
        $this->assertCount(1, $chunks);
        $this->assertSame($text, $chunks[0]);
    }

    public function test_two_short_paragraphs_merge_into_one_chunk(): void
    {
        $text = "First paragraph with enough content to pass the minimum length filter.\n\nSecond paragraph that also qualifies for storage.";
        $chunks = $this->chunker->chunk($text);
        $this->assertCount(1, $chunks);
        $this->assertStringContainsString('First paragraph', $chunks[0]);
        $this->assertStringContainsString('Second paragraph', $chunks[0]);
    }

    public function test_oversized_single_paragraph_splits_at_sentence_boundary(): void
    {
        // Build a paragraph longer than MAX_CHUNK_CHARS (1500) by repeating sentences.
        $sentence = 'This is a complete sentence that contributes to the overall length. ';
        $text = str_repeat($sentence, 30); // ~1980 chars total
        $chunks = $this->chunker->chunk($text);
        $this->assertGreaterThan(1, count($chunks));
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(1500 + mb_strlen($sentence), mb_strlen($chunk));
        }
    }

    public function test_strips_yaml_frontmatter(): void
    {
        $text = "---\ntitle: My Note\ndate: 2026-04-08\n---\n\n" .
                'This is the actual content of the note, long enough to be stored as a chunk.';
        $chunks = $this->chunker->chunk($text);
        $this->assertCount(1, $chunks);
        $this->assertStringNotContainsString('title: My Note', $chunks[0]);
        $this->assertStringContainsString('actual content', $chunks[0]);
    }

    public function test_strips_markdown_horizontal_rules(): void
    {
        $text = "First section with enough text to meet the minimum character requirement.\n\n---\n\n" .
                'Second section that also has sufficient content to be retained.';
        $chunks = $this->chunker->chunk($text);
        foreach ($chunks as $chunk) {
            $this->assertStringNotContainsString('---', $chunk);
        }
    }

    public function test_paragraphs_below_minimum_are_discarded(): void
    {
        $text = "# Heading\n\nA real paragraph with enough words to qualify as a meaningful memory chunk.\n\n---\n\nAnother real paragraph that also qualifies and should be included in the output.";
        $chunks = $this->chunker->chunk($text);
        // The heading '# Heading' and hrule are below MIN_CHUNK_CHARS; only the two real paragraphs survive.
        $this->assertCount(1, count($chunks) > 1 ? $chunks : $chunks);
        foreach ($chunks as $chunk) {
            $this->assertStringNotContainsString('# Heading', $chunk);
        }
    }

    public function test_all_chunks_meet_minimum_length(): void
    {
        $sentence = 'Each line contributes enough words to be considered a paragraph. ';
        $text = implode("\n\n", array_fill(0, 20, $sentence . $sentence));
        $chunks = $this->chunker->chunk($text);
        $this->assertNotEmpty($chunks);
        foreach ($chunks as $chunk) {
            $this->assertGreaterThanOrEqual(60, mb_strlen(trim($chunk)));
        }
    }

    public function test_chunk_content_covers_full_document(): void
    {
        $paragraphs = [];
        for ($i = 1; $i <= 5; $i++) {
            $paragraphs[] = "Paragraph {$i}: " . str_repeat("word{$i} ", 30);
        }
        $text = implode("\n\n", $paragraphs);
        $chunks = $this->chunker->chunk($text);
        $combined = implode(' ', $chunks);
        for ($i = 1; $i <= 5; $i++) {
            $this->assertStringContainsString("Paragraph {$i}", $combined);
        }
    }
}
