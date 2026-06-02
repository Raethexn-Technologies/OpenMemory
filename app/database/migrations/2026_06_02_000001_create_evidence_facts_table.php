<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evidence_facts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('user_id')->index();
            $table->uuid('source_node_id');
            $table->uuid('source_document_id')->nullable();
            $table->text('fact_text');
            $table->unsignedInteger('span_start')->nullable();
            $table->unsignedInteger('span_end')->nullable();
            $table->timestamp('observed_at')->nullable();
            $table->float('confidence')->default(1.0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('source_node_id')
                ->references('id')
                ->on('memory_nodes')
                ->onDelete('cascade');

            $table->foreign('source_document_id')
                ->references('id')
                ->on('memory_nodes')
                ->nullOnDelete();

            $table->index(['user_id', 'source_node_id']);
            $table->index(['user_id', 'source_document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evidence_facts');
    }
};
