<?php

namespace Tests\Unit;

use App\Services\Conversations\Archive\ArchiveException;
use App\Services\Conversations\Json\JsonArrayStreamReader;
use Tests\TestCase;

/**
 * The streaming reader is the load-bearing piece for large archives, so its
 * failure modes are tested directly rather than only through an adapter.
 */
class JsonArrayStreamReaderTest extends TestCase
{
    /**
     * @return resource
     */
    private function streamOf(string $contents)
    {
        $handle = fopen('php://memory', 'r+b');
        fwrite($handle, $contents);
        rewind($handle);

        return $handle;
    }

    /**
     * @return array<int, string>
     */
    private function read(string $json, int $maxElementBytes = 67108864): array
    {
        $reader = new JsonArrayStreamReader($this->streamOf($json), $maxElementBytes);

        return iterator_to_array($reader->elements(), false);
    }

    public function test_empty_array_yields_nothing(): void
    {
        $this->assertSame([], $this->read('[]'));
    }

    public function test_yields_each_object_element(): void
    {
        $elements = $this->read('[{"a":1},{"b":2},{"c":3}]');

        $this->assertCount(3, $elements);
        $this->assertSame(['a' => 1], json_decode($elements[0], true));
        $this->assertSame(['c' => 3], json_decode($elements[2], true));
    }

    public function test_braces_inside_strings_do_not_change_nesting(): void
    {
        $elements = $this->read('[{"text":"a } b { c ] d ["},{"text":"ok"}]');

        $this->assertCount(2, $elements);
        $this->assertSame('a } b { c ] d [', json_decode($elements[0], true)['text']);
    }

    public function test_escaped_quotes_and_backslashes_are_handled(): void
    {
        $json = '[{"text":"she said \"hi\" and \\\\ then left"},{"text":"second"}]';
        $elements = $this->read($json);

        $this->assertCount(2, $elements);
        $this->assertSame('she said "hi" and \\ then left', json_decode($elements[0], true)['text']);
    }

    public function test_nested_arrays_and_objects_stay_in_one_element(): void
    {
        $elements = $this->read('[{"a":[1,2,{"b":[3,{"c":4}]}]},{"d":5}]');

        $this->assertCount(2, $elements);
        $this->assertSame(4, json_decode($elements[0], true)['a'][2]['b'][1]['c']);
    }

    public function test_scalar_elements_are_yielded_individually(): void
    {
        $this->assertSame(['1', '2', 'true', 'null', '"text"'], $this->read('[1, 2, true, null, "text"]'));
    }

    public function test_unicode_content_survives_intact(): void
    {
        $elements = $this->read('[{"text":"Résumé — 日本語 — emoji 🙂"}]');

        $this->assertSame('Résumé — 日本語 — emoji 🙂', json_decode($elements[0], true)['text']);
    }

    public function test_byte_order_mark_is_skipped(): void
    {
        $this->assertCount(1, $this->read("\xEF\xBB\xBF[{\"a\":1}]"));
    }

    public function test_whitespace_and_newlines_between_elements_are_ignored(): void
    {
        $this->assertCount(2, $this->read("[\n  {\"a\":1},\n\n  {\"b\":2}\n]\n"));
    }

    public function test_non_array_top_level_is_rejected(): void
    {
        $this->expectException(ArchiveException::class);
        $this->read('{"conversations":[]}');
    }

    public function test_empty_stream_is_rejected(): void
    {
        $this->expectException(ArchiveException::class);
        $this->read('');
    }

    public function test_unterminated_array_is_rejected(): void
    {
        $this->expectException(ArchiveException::class);
        $this->read('[{"a":1},{"b":2}');
    }

    public function test_unterminated_string_is_rejected(): void
    {
        $this->expectException(ArchiveException::class);
        $this->read('[{"a":"unclosed]');
    }

    public function test_oversized_element_is_rejected(): void
    {
        $big = '[{"text":"' . str_repeat('x', 5000) . '"}]';

        $this->expectException(ArchiveException::class);
        $this->read($big, 512);
    }

    public function test_malformed_element_is_reported_and_skipped(): void
    {
        // A structurally balanced element that is still not valid JSON must not
        // abort the whole file: one bad conversation should never cost the rest.
        $reader = new JsonArrayStreamReader($this->streamOf('[{"a":1},{"b":,},{"c":3}]'));
        $malformed = [];

        $decoded = iterator_to_array($reader->decodedElements(function (string $raw) use (&$malformed): void {
            $malformed[] = $raw;
        }), false);

        $this->assertCount(2, $decoded);
        $this->assertCount(1, $malformed);
    }

    public function test_large_array_streams_without_buffering_everything(): void
    {
        $body = str_repeat('y', 4096);
        $parts = [];

        for ($i = 0; $i < 400; $i++) {
            $parts[] = '{"i":' . $i . ',"text":"' . $body . '"}';
        }

        $reader = new JsonArrayStreamReader($this->streamOf('[' . implode(',', $parts) . ']'));

        $before = memory_get_usage();
        $count = 0;
        $lastIndex = null;

        foreach ($reader->elements() as $raw) {
            $count++;
            $lastIndex = json_decode($raw, true)['i'];
        }

        $growth = memory_get_usage() - $before;

        $this->assertSame(400, $count);
        $this->assertSame(399, $lastIndex);
        // The document is roughly 1.6 MB. Peak retention should stay near one
        // element plus a chunk, not the whole file.
        $this->assertLessThan(1024 * 1024, $growth);
    }
}
