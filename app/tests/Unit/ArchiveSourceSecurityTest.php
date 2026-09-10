<?php

namespace Tests\Unit;

use App\Services\Conversations\Archive\ArchiveEntryName;
use App\Services\Conversations\Archive\ArchiveException;
use App\Services\Conversations\Archive\ArchiveLimits;
use App\Services\Conversations\Archive\ArchiveSourceFactory;
use App\Services\Conversations\Archive\ZipArchiveSource;
use Tests\Support\ConversationFixtures;
use Tests\TestCase;

/**
 * An archive is untrusted input. These tests cover the ways a hostile or simply
 * broken one could damage the host before any parsing begins.
 */
class ArchiveSourceSecurityTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = ConversationFixtures::scratchDir('archive');
    }

    protected function tearDown(): void
    {
        ConversationFixtures::removeDir($this->dir);
        parent::tearDown();
    }

    private function limits(array $overrides = []): ArchiveLimits
    {
        return (new ArchiveLimits(
            maxTotalUncompressedBytes: 50 * 1024 * 1024,
            maxEntryUncompressedBytes: 25 * 1024 * 1024,
            maxEntries: 1000,
            maxCompressionRatio: 400.0,
            maxJsonElementBytes: 1024 * 1024,
            maxMessageChars: 10000,
        ))->with($overrides);
    }

    public function test_entry_name_rejects_parent_directory_traversal(): void
    {
        $this->assertNull(ArchiveEntryName::normalize('../../etc/passwd'));
        $this->assertNull(ArchiveEntryName::normalize('conversations/../../secrets.json'));
    }

    public function test_entry_name_rejects_absolute_and_windows_paths(): void
    {
        $this->assertNull(ArchiveEntryName::normalize('/etc/passwd'));
        $this->assertNull(ArchiveEntryName::normalize('C:\\Windows\\System32\\config'));
        $this->assertNull(ArchiveEntryName::normalize('//server/share/file.json'));
    }

    public function test_entry_name_rejects_null_bytes_and_empty_names(): void
    {
        $this->assertNull(ArchiveEntryName::normalize("conversations.json\0.txt"));
        $this->assertNull(ArchiveEntryName::normalize(''));
    }

    public function test_entry_name_normalizes_backslashes_and_redundant_segments(): void
    {
        $this->assertSame('Takeout/My Activity/MyActivity.json', ArchiveEntryName::normalize('Takeout\\My Activity\\MyActivity.json'));
        $this->assertSame('a/b.json', ArchiveEntryName::normalize('./a//./b.json'));
    }

    public function test_traversal_entries_are_excluded_from_the_index_and_reported(): void
    {
        $path = ConversationFixtures::writeZip($this->dir . '/evil.zip', [
            'conversations.json' => '[]',
            '../escape.json' => '{"a":1}',
        ]);

        $source = ZipArchiveSource::open($path, $this->limits());

        try {
            $this->assertSame(['conversations.json'], $source->entries());
            $this->assertFalse($source->has('../escape.json'));

            $refused = $source->refusedEntries();
            $this->assertCount(1, $refused);
            $this->assertSame('unsafe_entry_name', $refused[0]['reason']);
        } finally {
            $source->close();
        }
    }

    public function test_reading_an_unknown_entry_throws(): void
    {
        $path = ConversationFixtures::writeZip($this->dir . '/plain.zip', ['conversations.json' => '[]']);
        $source = ZipArchiveSource::open($path, $this->limits());

        try {
            $this->expectException(ArchiveException::class);
            $source->read('nope.json');
        } finally {
            $source->close();
        }
    }

    public function test_entry_count_limit_is_enforced(): void
    {
        $entries = [];

        for ($i = 0; $i < 12; $i++) {
            $entries["file{$i}.json"] = '[]';
        }

        $path = ConversationFixtures::writeZip($this->dir . '/many.zip', $entries);

        $this->expectException(ArchiveException::class);
        $this->expectExceptionMessageMatches('/entries, above the configured limit/');
        ZipArchiveSource::open($path, $this->limits(['max_entries' => 5]));
    }

    public function test_per_entry_size_limit_is_enforced(): void
    {
        $path = ConversationFixtures::writeZip($this->dir . '/big.zip', [
            'conversations.json' => str_repeat('a', 200000),
        ]);

        $this->expectException(ArchiveException::class);
        $this->expectExceptionMessageMatches('/above the per-entry limit/');
        ZipArchiveSource::open($path, $this->limits(['max_entry_uncompressed_bytes' => 1024]));
    }

    public function test_total_size_limit_is_enforced(): void
    {
        $path = ConversationFixtures::writeZip($this->dir . '/total.zip', [
            'a.json' => str_repeat('a', 40000),
            'b.json' => str_repeat('b', 40000),
        ]);

        $this->expectException(ArchiveException::class);
        $this->expectExceptionMessageMatches('/total limit/');
        ZipArchiveSource::open($path, $this->limits(['max_total_uncompressed_bytes' => 50000]));
    }

    public function test_decompression_bomb_ratio_is_rejected(): void
    {
        // Two megabytes of a single repeated byte compresses far past any ratio
        // an ordinary transcript archive reaches.
        $path = ConversationFixtures::writeZip($this->dir . '/bomb.zip', [
            'conversations.json' => str_repeat("\0", 2 * 1024 * 1024),
        ]);

        $this->expectException(ArchiveException::class);
        $this->expectExceptionMessageMatches('/compression ratio/');
        ZipArchiveSource::open($path, $this->limits(['max_compression_ratio' => 20.0]));
    }

    public function test_factory_opens_a_zip_by_content_not_extension(): void
    {
        $path = ConversationFixtures::writeZip($this->dir . '/export.bin', ['conversations.json' => '[]']);
        $source = (new ArchiveSourceFactory())->open($path, $this->limits());

        try {
            $this->assertSame(['conversations.json'], $source->entries());
        } finally {
            $source->close();
        }
    }

    public function test_factory_opens_a_bare_json_file_as_a_single_entry(): void
    {
        $path = ConversationFixtures::writeJson($this->dir . '/conversations.json', []);
        $source = (new ArchiveSourceFactory())->open($path, $this->limits());

        try {
            $this->assertSame(['conversations.json'], $source->entries());
            $this->assertNotNull($source->sha256());
        } finally {
            $source->close();
        }
    }

    public function test_factory_opens_a_directory_and_indexes_nested_entries(): void
    {
        @mkdir($this->dir . '/Takeout/My Activity/Gemini Apps', 0777, true);
        file_put_contents($this->dir . '/Takeout/My Activity/Gemini Apps/MyActivity.json', '[]');

        $source = (new ArchiveSourceFactory())->open($this->dir, $this->limits());

        try {
            $this->assertContains('Takeout/My Activity/Gemini Apps/MyActivity.json', $source->entries());
            // A directory has no single byte sequence to hash.
            $this->assertNull($source->sha256());
        } finally {
            $source->close();
        }
    }

    public function test_missing_path_is_reported_clearly(): void
    {
        $this->expectException(ArchiveException::class);
        $this->expectExceptionMessageMatches('/Archive not found/');
        (new ArchiveSourceFactory())->open($this->dir . '/does-not-exist.zip', $this->limits());
    }
}
