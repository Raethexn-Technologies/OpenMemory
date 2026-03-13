<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('graph_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('user_id')->index();
            $table->timestamp('snapshot_at')->index();
            // Cluster-level aggregate: {clusters: [{id, node_ids, mean_weight, node_count}]}
            // Does not store individual node weights — only cluster-level stats for the temporal axis.
            $table->json('payload');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('graph_snapshots');
    }
};
