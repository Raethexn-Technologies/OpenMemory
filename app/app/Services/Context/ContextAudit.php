<?php

namespace App\Services\Context;

use Illuminate\Support\Facades\DB;

class ContextAudit
{
    public function record(ContextCaller $caller, string $id, string $operation, string $outcome, array $sources = [], int $count = 0, int $duration = 0): void
    {
        $summary = [];
        foreach ($sources as $source => $result) {
            $summary[$source] = [
                'status' => $result['status'],
                'searched' => $result['searched'] ?? false,
                'returned_count' => $result['returned_count'] ?? 0,
            ];
        }
        DB::table('context_access_events')->insert([
            'id' => $id, 'owner_id' => $caller->owner->id,
            'application_id' => $caller->applicationId,
            'operation' => $operation, 'outcome' => $outcome,
            'sources' => json_encode($summary, JSON_THROW_ON_ERROR),
            'fragment_count' => $count, 'duration_ms' => max(0, $duration),
            'created_at' => now(),
        ]);
    }
}
