<?php

namespace App\Console\Commands;

use App\Models\GraphSnapshot;
use App\Models\MemoryNode;
use App\Services\ClusterDetectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Captures a cluster-level snapshot of every user's memory graph.
 *
 * Snapshots feed the Three.js mission control surface's temporal axis, letting
 * operators scrub back through graph state during long multi-agent runs. Only
 * cluster-level aggregates (id, node_ids, mean_weight, node_count) are stored —
 * not individual node weights — which keeps the payload small.
 *
 * A maximum of 96 snapshots are kept per user (24 hours at 15-minute intervals).
 * Older snapshots are pruned on each write.
 *
 * Schedule this to run every 15 minutes in routes/console.php:
 *   Schedule::command('graph:snapshot')->everyFifteenMinutes();
 */
class TakeGraphSnapshot extends Command
{
    protected $signature = 'graph:snapshot';

    protected $description = 'Capture a cluster-level snapshot of every user\'s memory graph for the temporal axis.';

    private const MAX_SNAPSHOTS_PER_USER = 96;

    public function handle(ClusterDetectionService $detector): int
    {
        $userIds = MemoryNode::query()
            ->select('user_id')
            ->distinct()
            ->pluck('user_id');

        if ($userIds->isEmpty()) {
            $this->info('No users with memory nodes. Nothing to snapshot.');

            return Command::SUCCESS;
        }

        $now = Carbon::now();
        $count = 0;

        foreach ($userIds as $userId) {
            $clusters = $detector->detect($userId);

            GraphSnapshot::create([
                'user_id' => $userId,
                'snapshot_at' => $now,
                'payload' => ['clusters' => $clusters],
            ]);

            // Prune to the most recent MAX_SNAPSHOTS_PER_USER snapshots for this user.
            $oldest = GraphSnapshot::where('user_id', $userId)
                ->orderByDesc('snapshot_at')
                ->skip(self::MAX_SNAPSHOTS_PER_USER)
                ->take(PHP_INT_MAX)
                ->pluck('id');

            if ($oldest->isNotEmpty()) {
                GraphSnapshot::whereIn('id', $oldest)->delete();
            }

            $count++;
        }

        $this->info("Snapshots written for {$count} user(s).");

        return Command::SUCCESS;
    }
}
