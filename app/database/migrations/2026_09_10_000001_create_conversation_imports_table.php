<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per archive import run.
 *
 * This table is the outermost link of the provenance chain. Every normalized
 * conversation and every raw record points back to the import that produced it,
 * and the import records which physical archive it read, how large that archive
 * was, and the SHA-256 of its bytes. That makes the question "where did this
 * sentence come from" answerable all the way down to a file on disk.
 *
 * Imports are idempotent, so the same archive can be imported repeatedly. Each
 * attempt gets its own row; the stats column records what changed on that run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('user_id')->index();
            $table->string('provider', 32)->index();
            $table->string('adapter_version', 32);

            // Display label (usually the archive filename) and the full local
            // path. The path stays local: it is never sent to a model or an
            // MCP client, and the review UI shows the label only.
            $table->string('source_label', 255);
            $table->text('source_path')->nullable();
            $table->unsignedBigInteger('source_bytes')->nullable();
            $table->string('source_sha256', 64)->nullable()->index();

            // pending | running | completed | failed
            $table->string('status', 16)->default('pending')->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            // Counters: conversations/messages seen, new, updated, unchanged,
            // plus skipped records. Shown verbatim in the import report.
            $table->json('stats')->nullable();

            // Non-fatal problems: records the adapter could not understand,
            // truncated bodies, missing timestamps. Never silently dropped.
            $table->json('warnings')->nullable();

            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_imports');
    }
};
