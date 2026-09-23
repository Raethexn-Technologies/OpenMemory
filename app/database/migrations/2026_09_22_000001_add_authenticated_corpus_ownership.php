<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('owner_uuid')->nullable()->unique();
        });
        DB::table('users')->orderBy('id')->chunkById(100, function ($users) {
            foreach ($users as $user) {
                $uuid = (string) Str::uuid();
                DB::table('users')->where('id', $user->id)->update(['owner_uuid' => $uuid]);
            }
        });
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('owner_uuid')->nullable(false)->change();
        });
        Schema::create('corpus_owner_bindings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('owner_key')->unique();
            $table->timestamps();
        });
        DB::table('users')->orderBy('id')->chunkById(100, function ($users) {
            foreach ($users as $user) {
                DB::table('corpus_owner_bindings')->insert([
                    'user_id' => $user->id, 'owner_key' => $user->owner_uuid,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corpus_owner_bindings');
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['owner_uuid']);
            $table->dropColumn('owner_uuid');
        });
    }
};
