<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\SourceConnection;
use App\Models\SourceResource;
use App\Models\User;
use App\Services\Context\ContextApplications;
use App\Services\Context\ContextCaller;
use App\Services\Context\ContextPolicy;
use App\Services\Context\ContextRequest;
use App\Services\Context\ContextResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\ConversationFixtures;
use Tests\TestCase;

class ContextPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_bounded_three_source_work_has_a_defensible_query_budget(): void
    {
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Http::preventStrayRequests();
        config(['disclosure.model_operations' => []]);
        $owner = User::factory()->create();
        $key = $owner->corpusOwnerKey();
        $fixture = ConversationFixtures::temporalPortability();
        for ($batch = 0; $batch < 10; $batch++) {
            $rows = [];
            for ($i = 0; $i < 1000; $i++) {
                $rows[] = ['owner_id' => $owner->id, 'memory_id' => (string) Str::uuid(),
                    'content' => $batch === 0 && $i < 100 ? $fixture['text'] : 'Synthetic gardening note.',
                    'attribution' => 'user_asserted', 'state' => 'active', 'revision' => 1,
                    'created_at' => '2025-01-02 12:00:00', 'updated_at' => '2025-01-02 12:00:00'];
            }
            DB::table('native_memories')->insert($rows);
        }
        $conversation = Conversation::create(['user_id' => $key, 'provider' => 'claude',
            'provider_conversation_id' => 'performance-fixture', 'title' => 'Synthetic discussion',
            'content_hash' => hash('sha256', 'fixture'), 'parser_version' => 'fixture']);
        $rows = [];
        for ($i = 0; $i < 1000; $i++) {
            $rows[] = ['id' => (string) Str::uuid(), 'conversation_id' => $conversation->id,
                'user_id' => $key, 'provider' => 'claude', 'provider_message_id' => 'fixture-'.$i,
                'sequence' => $i, 'on_active_path' => true, 'role' => 'user',
                'content_text' => $i < 100 ? $fixture['text'] : 'Synthetic gardening discussion.',
                'content_hash' => hash('sha256', 'fixture-'.$i), 'provider_created_at' => '2025-01-02 12:00:00',
                'created_at' => '2025-01-02 12:00:00', 'updated_at' => '2025-01-02 12:00:00'];
        }
        DB::table('conversation_messages')->insert($rows);
        $connection = new SourceConnection(['provider' => 'github', 'external_account_id' => '501',
            'external_account_login' => 'fixture', 'credential' => 'github_pat_syntheticNeverAuthenticate',
            'credential_expires_at' => now()->addDay(), 'query_disclosures' => ['history'], 'revision' => 1]);
        $connection->owner_id = $owner->id;
        $connection->save();
        $resources = [];
        for ($i = 1; $i <= 3; $i++) {
            $resources[] = SourceResource::create(['connection_id' => $connection->id, 'external_id' => (string) $i,
                'reference' => 'fixture/project'.$i, 'selected' => true, 'revision' => 1])->id;
        }
        $registration = app(ContextApplications::class)->create($owner, ['name' => 'Synthetic measurement',
            'capabilities' => ContextPolicy::CAPABILITIES, 'source_resources' => $resources]);
        $httpCount = 0;
        Http::fake(function ($request) use (&$httpCount) {
            $httpCount++;
            preg_match('~/project([1-3])~', $request->url(), $matches);
            $repo = (int) $matches[1];
            if (! str_contains($request->url(), '/commits')) {
                return Http::response(['id' => $repo, 'full_name' => 'fixture/project'.$repo]);
            }
            $commits = [];
            for ($i = 1; $i <= 10; $i++) {
                $commits[] = ['sha' => str_pad(dechex($repo * 100 + ((int) $request['page']) * 10 + $i), 40, '0', STR_PAD_LEFT),
                    'commit' => ['message' => 'Synthetic maintenance commit.', 'committer' => ['date' => '2025-01-02T12:00:00Z']],
                    'author' => ['id' => 501, 'login' => 'fixture']];
            }

            return Http::response($commits, 200, ['Link' => '<https://api.github.com/repos/fixture/project'.$repo.'/commits?page=3>; rel="next"']);
        });
        $queries = 0;
        $tables = $callers = [];
        DB::listen(function ($event) use (&$queries, &$tables, &$callers) {
            $queries++;
            preg_match('/(?:from|into|update) ["`]?([a-z_]+)/i', $event->sql, $match);
            $table = $match[1] ?? 'schema';
            $tables[$table] = ($tables[$table] ?? 0) + 1;
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40) as $frame) {
                if (str_starts_with($frame['class'] ?? '', 'App\\')) {
                    $name = $frame['class'].'::'.$frame['function'];
                    $callers[$name] = ($callers[$name] ?? 0) + 1;
                    break;
                }
            }
        });
        $started = hrtime(true);
        $bundle = app(ContextResolver::class)->resolve(new ContextCaller($owner, $registration['application']['id'], 1),
            ContextRequest::fromArray(['version' => 'context-request-v1', 'query' => 'portability',
                'sources' => ['native_memory', 'history', 'github'], 'limit' => 20, 'per_source_limit' => 10,
                'temporal' => ['from_source' => 'history', 'to_source' => 'github', 'days' => 1]]))->toArray();
        $elapsed = round((hrtime(true) - $started) / 1000000, 2);
        $this->assertCount(20, $bundle['fragments']);
        $this->assertSame(9, $httpCount);
        $this->assertLessThanOrEqual(160, $queries);
        $this->assertArrayNotHasKey('conversation_raw_records', $tables);
        if (getenv('OM_CONTEXT_PROFILE') === '1') {
            fwrite(STDOUT, json_encode(['queries' => $queries, 'http_requests' => $httpCount, 'elapsed_ms' => $elapsed,
                'bundle_bytes' => strlen(json_encode($bundle)), 'tables' => $tables, 'callers' => $callers]).PHP_EOL);
        }
    }
}
