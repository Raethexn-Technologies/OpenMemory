<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memory_nodes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('user_id')->index();           // ICP principal or session fallback
            $table->string('session_id')->nullable()->index();
            $table->enum('type', ['memory', 'person', 'project', 'document', 'task', 'event', 'concept']);
            $table->enum('sensitivity', ['public', 'private', 'sensitive'])->default('public');
            $table->string('label', 120);                // Short display name for the node
            $table->text('content');                     // Full memory content
            $table->json('tags')->nullable();            // Concept keywords for edge auto-wiring
            $table->float('confidence')->default(1.0);  // 0–1 trust score
            $table->string('source', 32)->default('chat'); // chat|manual|extracted
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memory_nodes');
    }
};
