<?php

namespace App\Console\Commands;

use App\Models\CorpusOwnerBinding;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BindCorpusOwner extends Command
{
    protected $signature = 'openmemory:corpus:bind {owner : Exact existing import or legacy owner key} {--email= : Existing OpenMemory login email}';

    protected $description = 'Explicitly assign a legacy corpus namespace to an authenticated local account';

    public function handle(): int
    {
        $owner = (string) $this->argument('owner');
        $email = strtolower(trim((string) $this->option('email')));
        if ($owner === '' || trim($owner) !== $owner || mb_strlen($owner) > 255 || preg_match('/[\x00-\x1F\x7F]/', $owner)) {
            $this->error('Supply an exact nonempty owner key of at most 255 characters without control characters.');

            return self::FAILURE;
        }
        try {
            DB::transaction(function () use ($owner, $email): void {
                $user = User::where('email', $email)->lockForUpdate()->first();
                if (! $user) {
                    throw new RuntimeException('Create the target OpenMemory user first.');
                }
                $binding = CorpusOwnerBinding::where('user_id', $user->id)->lockForUpdate()->first();
                if (! $binding) {
                    throw new RuntimeException('The account has no ownership binding; manual repair is required.');
                }
                if ($binding->owner_key === $owner) {
                    return;
                }
                if (CorpusOwnerBinding::where('owner_key', $owner)->exists()
                    || User::where('owner_uuid', $owner)->exists()
                    || DB::table('agents')->where('graph_user_id', $owner)->exists()) {
                    throw new RuntimeException('That owner key is already assigned or reserved.');
                }
                if ($binding->owner_key !== $user->owner_uuid) {
                    throw new RuntimeException('An existing legacy binding cannot be replaced by this command.');
                }
                foreach ([
                    'conversations', 'conversation_imports', 'conversation_messages', 'conversation_raw_records',
                    'memory_nodes', 'memory_edges', 'evidence_facts', 'graph_snapshots', 'redaction_policies',
                ] as $table) {
                    if (DB::table($table)->where('user_id', $binding->owner_key)->exists()) {
                        throw new RuntimeException('The current ownership namespace already contains data.');
                    }
                }
                foreach (['agents', 'shared_memory_edges'] as $table) {
                    if (DB::table($table)->where('owner_user_id', $binding->owner_key)->exists()) {
                        throw new RuntimeException('The current ownership namespace already contains data.');
                    }
                }
                if (cache()->get('mock_icp_'.$binding->owner_key, []) !== []) {
                    throw new RuntimeException('The current ownership namespace already contains mock memories.');
                }
                $binding->update(['owner_key' => $owner]);
            });
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->info('Corpus ownership is bound. Existing records were not rewritten.');

        return self::SUCCESS;
    }
}
