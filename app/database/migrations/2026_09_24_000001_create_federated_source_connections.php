<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_connections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('external_account_id', 32);
            $table->string('external_account_login', 100);
            $table->text('credential')->nullable();
            $table->timestamp('credential_expires_at');
            $table->timestamp('disconnected_at')->nullable();
            $table->timestamp('retry_at')->nullable();
            $table->json('query_disclosures');
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->unique(['owner_id', 'provider']);
        });
        Schema::create('source_resources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('connection_id')->constrained('source_connections')->cascadeOnDelete();
            $table->string('external_id', 32);
            $table->string('reference', 200);
            $table->boolean('selected')->default(false);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->unique(['connection_id', 'external_id']);
        });
        Schema::table('context_applications', function (Blueprint $table) {
            $table->json('source_resources')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('context_applications', fn (Blueprint $table) => $table->dropColumn('source_resources'));
        Schema::dropIfExists('source_resources');
        Schema::dropIfExists('source_connections');
    }
};
