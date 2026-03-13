<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Shared memory edges represent cross-agent Physarum conductance.
     *
     * When two agents both access nodes derived from the same ICP memory content,
     * a shared edge accumulates weight from each agent's reinforcement events,
     * multiplied by that agent's trust_score. The combined weight represents the
     * collective signal: how important this memory is to the group, not just one agent.
     *
     * Agents are stored in canonical order (lower UUID as agent_a) to prevent
     * duplicate edges in both directions. The content_hash is SHA-256 of the
     * shared memory content and is the join key between the two agents' local nodes.
     */
    public function up(): void
    {
        Schema::create('shared_memory_edges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('owner_user_id')->index();  // human owner of both agents
            $table->uuid('agent_a_id');
            $table->uuid('agent_b_id');
            $table->uuid('node_a_id');                 // agent A's local MemoryNode
            $table->uuid('node_b_id');                 // agent B's local MemoryNode
            $table->string('content_hash', 64)->index(); // SHA-256 of shared content
            $table->float('weight')->default(0.5);
            $table->unsignedInteger('access_count')->default(0);
            $table->timestamp('last_accessed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['agent_a_id', 'agent_b_id', 'content_hash']);
            $table->index(['owner_user_id', 'content_hash']);

            $table->foreign('agent_a_id')->references('id')->on('agents')->cascadeOnDelete();
            $table->foreign('agent_b_id')->references('id')->on('agents')->cascadeOnDelete();
            $table->foreign('node_a_id')->references('id')->on('memory_nodes')->cascadeOnDelete();
            $table->foreign('node_b_id')->references('id')->on('memory_nodes')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shared_memory_edges');
    }
};
