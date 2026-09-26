<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('context_applications', function (Blueprint $table) {
            $table->json('model_disclosure')->nullable();
        });
        Schema::table('context_access_events', function (Blueprint $table) {
            $table->uuid('context_request_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('context_access_events', function (Blueprint $table) {
            $table->dropIndex(['context_request_id']);
            $table->dropColumn('context_request_id');
        });
        Schema::table('context_applications', fn (Blueprint $table) => $table->dropColumn('model_disclosure'));
    }
};
