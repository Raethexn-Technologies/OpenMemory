<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneContextAccessEvents extends Command
{
    protected $signature = 'context:audit:prune';

    protected $description = 'Remove context access metadata older than thirty days';

    public function handle(): int
    {
        $count = DB::table('context_access_events')->where('created_at', '<', now()->subDays(30))->delete();
        $this->info('Removed '.$count.' expired context access events.');

        return self::SUCCESS;
    }
}
