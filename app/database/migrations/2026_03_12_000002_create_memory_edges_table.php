<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memory_edges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('user_id')->index();
            $table->uuid('from_node_id');
            $table->uuid('to_node_id');
            $table->enum('relationship', [
                'related_to',
                'part_of',
                'mentioned_with',
                'created_from',
                'about_person',
                'same_topic_as',
                'supersedes',
                'caused_by',
                'contradicts',
                'depends_on',
            ])->default('related_to');
            $table->float('weight')->default(0.5);       // 0–1 edge strength
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('from_node_id')->references('id')->on('memory_nodes')->onDelete('cascade');
            $table->foreign('to_node_id')->references('id')->on('memory_nodes')->onDelete('cascade');
            $table->index(['from_node_id', 'to_node_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memory_edges');
    }
};
