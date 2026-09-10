<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provider-neutral message records.
 *
 * content_text is the redacted projection of the message body. It is the only
 * representation that retrieval, prompts, and the review UI ever read. The
 * unredacted original stays in conversation_raw_records, which no retrieval path
 * touches. That split is what lets the project keep the source intact without
 * widening the surface through which secrets can escape.
 *
 * Branching is preserved rather than flattened. ChatGPT and Claude both allow a
 * user to edit a prompt, which forks the conversation. parent_provider_message_id
 * keeps the tree, and on_active_path marks the branch the provider considered
 * current, so retrieval can prefer the path the user actually kept while the
 * abandoned branches remain inspectable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('conversation_id');
            $table->string('user_id')->index();
            $table->string('provider', 32);

            $table->string('provider_message_id', 255);
            $table->string('parent_provider_message_id', 255)->nullable();

            // Position along the active path, or document order for providers
            // that export a flat list. Stable across re-imports.
            $table->unsignedInteger('sequence')->default(0);
            $table->boolean('on_active_path')->default(true)->index();

            // user | assistant | system | tool | unknown. Normalized from each
            // provider's own vocabulary; unrecognized roles become 'unknown'
            // rather than being coerced into a role the archive did not claim.
            $table->string('role', 16)->index();
            $table->string('author_name', 120)->nullable();

            // Provider content type, kept verbatim for auditing which records a
            // parser handled as text versus tool output versus media.
            $table->string('content_type', 48)->nullable();

            $table->text('content_text');

            // Structural summary of the original content blocks: type, order, and
            // size. Carries no duplicate body text, so it stays cheap to store
            // and safe to render.
            $table->json('content_blocks')->nullable();

            $table->string('model_slug', 120)->nullable();
            $table->timestamp('provider_created_at')->nullable()->index();
            $table->unsignedInteger('char_count')->default(0);

            // Attachment metadata only: identifier, declared name, media type,
            // size. Attachment bytes are not imported in this milestone.
            $table->json('attachments')->nullable();

            $table->json('provider_metadata')->nullable();
            $table->json('redaction')->nullable();
            $table->string('content_hash', 64);

            $table->timestamps();

            $table->foreign('conversation_id')->references('id')->on('conversations')->cascadeOnDelete();
            $table->unique(['conversation_id', 'provider_message_id'], 'conversation_messages_identity_unique');
            $table->index(['user_id', 'provider_created_at'], 'conversation_messages_user_time_index');
            $table->index(['conversation_id', 'sequence'], 'conversation_messages_order_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_messages');
    }
};
