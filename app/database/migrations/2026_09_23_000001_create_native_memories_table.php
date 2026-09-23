<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('native_memories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('memory_id');
            $table->text('content');
            $table->string('attribution', 32);
            $table->string('state', 16)->default('active');
            $table->uuid('superseded_by')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->unique(['owner_id', 'memory_id']);
            $table->index(['owner_id', 'state', 'memory_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('native_memories');
    }
};
