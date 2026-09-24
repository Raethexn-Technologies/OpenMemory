<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('context_applications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('token_hash', 64)->unique();
            $table->json('capabilities');
            $table->unsignedInteger('grant_revision')->default(1);
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::create('context_access_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('application_id')->nullable();
            $table->foreign('application_id')->references('id')->on('context_applications')->nullOnDelete();
            $table->string('operation', 40);
            $table->string('outcome', 32);
            $table->json('sources');
            $table->unsignedInteger('fragment_count')->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamp('created_at');
            $table->index(['owner_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('context_access_events');
        Schema::dropIfExists('context_applications');
    }
};
