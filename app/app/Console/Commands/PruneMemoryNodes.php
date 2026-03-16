<?php

namespace App\Console\Commands;

use App\Models\MemoryEdge;
use App\Models\MemoryNode;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Prune dormant memory nodes that the Physarum model has forgotten.
 *
 * A node is pruned when both conditions hold:
 *   1. All of its edges have decayed to floor weight (<= 0.06).
 *   2. The node has not been accessed in the last 90 days.
 *
 * Nodes with no edges at all that are also older than 90 days are pruned
 * because they were never connected into the graph and carry no relational value.
 *
 * Pruning removes edges first, then the node. This is a hard delete — the data
 * is gone. Run this after the daily decay pass so the floor-weight condition
 * reflects the latest decay state.
 *
 * Run on a schedule (e.g. monthly) or trigger via the in-app button:
 *   POST /api/graph/prune
 */
class PruneMemoryNodes extends Command
{
    // Nodes must be idle for this many days before becoming eligible for pruning.
    private const IDLE_DAYS = 90;

    // Edges at or below this weight are considered at floor (0.05 floor + rounding buffer).
    private const FLOOR_THRESHOLD = 0.06;

    protected $signature = 'memory:prune
                            {--user=        : Limit to a specific user_id}
                            {--dry-run      : Report what would be pruned without deleting}
                            {--days=90      : Minimum idle days before a node is eligible}';

    protected $description = 'Delete dormant nodes whose edges have all decayed to floor weight';

    public function handle(): int
    {
        $specificUser = $this->option('user');
        $dryRun       = $this->option('dry-run');
        $idleDays     = max(1, (int) $this->option('days'));
        $cutoff       = Carbon::now()->subDays($idleDays);

        $query = MemoryNode::query()
            ->when($specificUser, fn ($q) => $q->where('user_id', $specificUser))
            ->where(function ($q) use ($cutoff) {
                $q->where('last_accessed_at', '<', $cutoff)
                    ->orWhere(function ($inner) use ($cutoff) {
                        // Nodes never accessed and old enough.
                        $inner->whereNull('last_accessed_at')
                            ->where('created_at', '<', $cutoff);
                    });
            });

        $candidates = $query->pluck('id')->all();

        if (empty($candidates)) {
            $this->info('No idle nodes found.');

            return self::SUCCESS;
        }

        // Of those candidates, keep only nodes where every edge is at floor weight.
        // A node with any edge above floor is still active in the Physarum model.
        $activeEdges = MemoryEdge::where(function ($q) use ($candidates) {
            $q->whereIn('from_node_id', $candidates)
                ->orWhereIn('to_node_id', $candidates);
        })
            ->where('weight', '>', self::FLOOR_THRESHOLD)
            ->get(['from_node_id', 'to_node_id']);

        $activeNodes = $activeEdges
            ->flatMap(fn ($e) => [$e->from_node_id, $e->to_node_id])
            ->unique()
            ->all();

        $prunable = array_diff($candidates, $activeNodes);

        if (empty($prunable)) {
            $this->info('No dormant nodes with all-floor edges found.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->warn(sprintf('[dry-run] Would prune %d node(s).', count($prunable)));

            return self::SUCCESS;
        }

        // Delete edges first to avoid foreign-key violations, then nodes.
        MemoryEdge::whereIn('from_node_id', $prunable)
            ->orWhereIn('to_node_id', $prunable)
            ->delete();

        MemoryNode::whereIn('id', $prunable)->delete();

        $this->info(sprintf('Pruned %d dormant node(s) and their edges.', count($prunable)));

        return self::SUCCESS;
    }
}
