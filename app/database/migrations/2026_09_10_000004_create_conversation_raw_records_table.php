<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preserved source evidence.
 *
 * One row holds the exact provider JSON for one conversation, byte-identical to
 * what the archive contained. Nothing derived is ever the only surviving copy of
 * what a person said: normalization, redaction, summarization, and any later
 * extraction all sit above this table and can be recomputed from it.
 *
 * Access rules, enforced by construction rather than by convention:
 *   - Raw payloads are owner-scoped and are read only through an explicit
 *     owner-authenticated route.
 *   - No retrieval path, prompt builder, or MCP tool queries this table.
 *   - Deleting a conversation deletes its raw records with it.
 *
 * payload_sha256 is unique per (user, provider), so importing the same archive
 * twice stores one copy of each conversation's source rather than one per run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_raw_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Nullable so a preserved source outlives the import row that
            // created it. Pruning import history must never be able to take
            // evidence with it.
            $table->uuid('import_id')->nullable()->index();
            $table->string('user_id')->index();
            $table->string('provider', 32);
            $table->string('provider_conversation_id', 255);
            $table->uuid('conversation_id')->nullable()->index();

            // 'conversation' today. The column exists so archive-level manifests
            // and account metadata can be preserved later without a new table.
            $table->string('record_type', 32)->default('conversation');

            $table->longText('payload');
            $table->unsignedBigInteger('payload_bytes');
            $table->string('payload_sha256', 64);

            $table->timestamp('created_at')->useCurrent();

            $table->foreign('conversation_id')->references('id')->on('conversations')->nullOnDelete();
            $table->unique(['user_id', 'provider', 'payload_sha256'], 'conversation_raw_records_hash_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_raw_records');
    }
};
