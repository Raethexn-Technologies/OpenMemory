<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provider-neutral conversation records.
 *
 * A row here is one conversation from one provider, normalized into a shape that
 * does not privilege any single provider's export format. Fields the source
 * archive does not supply stay null rather than being guessed; provider-specific
 * structure that would be lost in normalization is preserved verbatim under
 * provider_metadata.
 *
 * Identity and idempotency: (user_id, provider, provider_conversation_id) is
 * unique. Providers that publish a stable conversation ID supply it directly.
 * Providers that do not get a deterministic synthesized ID derived from stable
 * content, so re-importing the same archive converges on the same row instead of
 * duplicating history.
 *
 * visibility defaults to 'private'. Imported history is never public, and never
 * enters the public memory graph, chat recall, or MCP responses by default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('user_id')->index();
            $table->string('provider', 32);
            $table->string('provider_conversation_id', 255);

            $table->string('title', 500)->nullable();

            // Account or workspace the conversation belongs to, when the archive
            // says so. Most personal exports do not, and these stay null.
            $table->string('source_account_label', 255)->nullable();
            $table->string('workspace_label', 255)->nullable();

            // Times reported by the provider. Distinct from created_at/updated_at,
            // which record when OpenMemory first saw and last touched the row.
            $table->timestamp('provider_created_at')->nullable()->index();
            $table->timestamp('provider_updated_at')->nullable();

            // Derived from message timestamps. These are what temporal queries
            // use, because a provider's conversation-level times are sometimes
            // absent even when individual messages are stamped.
            $table->timestamp('first_message_at')->nullable()->index();
            $table->timestamp('last_message_at')->nullable()->index();

            $table->unsignedInteger('message_count')->default(0);

            // Model identifiers actually named by the archive. Empty when unknown.
            $table->json('models')->nullable();

            // private | shared. Imported history starts private and stays there
            // until the owner changes it explicitly.
            $table->string('visibility', 16)->default('private')->index();

            // SHA-256 over the normalized conversation. Re-import compares this
            // to decide whether a known conversation actually changed.
            $table->string('content_hash', 64);

            $table->string('parser_version', 32);
            $table->uuid('first_import_id')->nullable();
            $table->uuid('last_import_id')->nullable();

            // Provider fields that do not map onto the neutral model.
            $table->json('provider_metadata')->nullable();

            // Redaction categories applied across this conversation's messages.
            $table->json('redaction')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'provider', 'provider_conversation_id'], 'conversations_provider_identity_unique');
            $table->index(['user_id', 'provider'], 'conversations_user_provider_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
