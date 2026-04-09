<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add 'goal' to the memory_nodes type enum.
     *
     * SQLite does not enforce enum check constraints, so no action is needed
     * there. PostgreSQL enforces them; we drop and recreate the constraint.
     *
     * 'goal' is a first-class node type for the second brain feature:
     * declared goals become graph anchors that bias context retrieval toward
     * the knowledge the user is actively working on.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE memory_nodes DROP CONSTRAINT IF EXISTS memory_nodes_type_check');
            DB::statement("ALTER TABLE memory_nodes ADD CONSTRAINT memory_nodes_type_check CHECK (type IN ('memory','person','project','document','task','event','concept','goal'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE memory_nodes DROP CONSTRAINT IF EXISTS memory_nodes_type_check');
            DB::statement("ALTER TABLE memory_nodes ADD CONSTRAINT memory_nodes_type_check CHECK (type IN ('memory','person','project','document','task','event','concept'))");
        }
    }
};
