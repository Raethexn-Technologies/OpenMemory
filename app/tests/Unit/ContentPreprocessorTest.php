<?php

namespace Tests\Unit;

use App\Services\Ingest\ContentPreprocessor;
use Tests\TestCase;

class ContentPreprocessorTest extends TestCase
{
    private ContentPreprocessor $preprocessor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->preprocessor = new ContentPreprocessor();
    }

    public function test_empty_input_reports_zero_reduction(): void
    {
        $result = $this->preprocessor->compress('');

        $this->assertSame('', $result['text']);
        $this->assertSame(0, $result['original_bytes']);
        $this->assertSame(0, $result['final_bytes']);
        $this->assertSame(0.0, $result['reduction_pct']);
    }

    public function test_plain_text_passes_through_unchanged(): void
    {
        $text = 'A single clean sentence with no noise.';
        $this->assertSame($text, $this->preprocessor->compressText($text));
    }

    public function test_html_block_tags_are_replaced_with_newlines(): void
    {
        $html = '<div>One</div><p>Two</p><p>Three</p>';
        $out = $this->preprocessor->compressText($html);

        $this->assertStringNotContainsString('<', $out);
        $this->assertStringNotContainsString('>', $out);
        $this->assertStringContainsString('One', $out);
        $this->assertStringContainsString('Two', $out);
        $this->assertStringContainsString('Three', $out);
    }

    public function test_script_block_is_dropped_entirely(): void
    {
        $html = "<p>Visible</p><script>var x = 1; while(true) {}</script><p>Also visible</p>";
        $out = $this->preprocessor->compressText($html);

        $this->assertStringContainsString('Visible', $out);
        $this->assertStringContainsString('Also visible', $out);
        $this->assertStringNotContainsString('var x', $out);
        $this->assertStringNotContainsString('while', $out);
    }

    public function test_html_entities_are_decoded(): void
    {
        $out = $this->preprocessor->compressText('Tom &amp; Jerry &lt;3');
        $this->assertSame('Tom & Jerry <3', $out);
    }

    public function test_long_urls_get_their_query_stripped(): void
    {
        $text = 'See https://example.com/some/long/path?utm_source=foo&utm_medium=bar&session=abc123xyz for details.';
        $out = $this->preprocessor->compressText($text);

        $this->assertStringNotContainsString('utm_source', $out);
        $this->assertStringNotContainsString('session=', $out);
        $this->assertStringContainsString('example.com', $out);
    }

    public function test_short_urls_are_preserved(): void
    {
        $text = 'Go to https://ex.com/p for info.';
        $out = $this->preprocessor->compressText($text);
        $this->assertStringContainsString('https://ex.com/p', $out);
    }

    public function test_excessive_blank_lines_collapse(): void
    {
        $text = "Line one.\n\n\n\n\nLine two.";
        $out = $this->preprocessor->compressText($text);
        $this->assertSame("Line one.\n\nLine two.", $out);
    }

    public function test_consecutive_duplicate_lines_are_dropped(): void
    {
        $text = "Header\nHeader\nHeader\nUnique line\nUnique line\nDifferent line";
        $out = $this->preprocessor->compressText($text);

        $this->assertSame("Header\nUnique line\nDifferent line", $out);
    }

    public function test_trailing_whitespace_is_trimmed_per_line(): void
    {
        $text = "Code line   \nNext line\t\t";
        $out = $this->preprocessor->compressText($text);
        $this->assertSame("Code line\nNext line", $out);
    }

    public function test_compress_reports_byte_reduction(): void
    {
        $html = str_repeat('<p>Padded content with quite a bit of HTML markup.</p>     ', 20);
        $result = $this->preprocessor->compress($html);

        $this->assertLessThan($result['original_bytes'], $result['final_bytes']);
        $this->assertGreaterThan(0.0, $result['reduction_pct']);
    }

    public function test_redaction_tokens_are_preserved(): void
    {
        // Confirm the preprocessor does not mangle redaction placeholders the
        // rest of the pipeline relies on for sensitive value substitution.
        $text = 'User shared [EMAIL] and [BANK_ROUTING#abc123] in chat.';
        $out = $this->preprocessor->compressText($text);

        $this->assertStringContainsString('[EMAIL]', $out);
        $this->assertStringContainsString('[BANK_ROUTING#abc123]', $out);
    }
}
