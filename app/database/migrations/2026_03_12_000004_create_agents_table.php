<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('owner_user_id')->index();  // ICP principal of the human who owns this agent
            $table->string('graph_user_id')->unique(); // partition key used in memory_nodes/memory_edges for this agent
            $table->string('name', 80);
            $table->string('principal', 128)->nullable(); // ICP principal for this agent if it has one
            // trust_score is the multiplier applied to this agent's ALPHA contribution on shared edges.
            // 0.0 = untrusted (contributes nothing to collective weights).
            // 1.0 = fully trusted (contributes full ALPHA).
            // Default 0.5 for new agents whose accuracy is unestablished.
            $table->float('trust_score')->default(0.5);
            $table->unsignedInteger('access_count')->default(0);
            $table->timestamp('last_active_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agents');
    }
};
