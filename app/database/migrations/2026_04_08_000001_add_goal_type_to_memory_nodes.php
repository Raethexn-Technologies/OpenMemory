<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add 'goal' to the memory_nodes type enum.
     *
     * PostgreSQL drops and recreates the check constraint directly.
     *
     * SQLite does not support ALTER TABLE ... DROP CONSTRAINT. The table is
     * recreated with the updated constraint using the standard table-rename
     * pattern. All columns and indexes are rebuilt explicitly. Foreign key
     * enforcement is suspended for the duration and restored after.
     *
     * 'goal' is a first-class node type for goal-biased context retrieval.
     * Declared goal nodes are seeded before weight-ranked nodes in
     * findContextSeeds(), biasing BFS toward knowledge connected to what the
     * user is actively working on.
     */
    public function up(): void
    {
        match (DB::getDriverName()) {
            'pgsql' => $this->upPostgres(),
            'sqlite' => $this->upSqlite(),
            default => null,
        };
    }

    public function down(): void
    {
        match (DB::getDriverName()) {
            'pgsql' => $this->downPostgres(),
            'sqlite' => $this->downSqlite(),
            default => null,
        };
    }

    // PostgreSQL path.

    private function upPostgres(): void
    {
        DB::statement('ALTER TABLE memory_nodes DROP CONSTRAINT IF EXISTS memory_nodes_type_check');
        DB::statement("ALTER TABLE memory_nodes ADD CONSTRAINT memory_nodes_type_check CHECK (type IN ('memory','person','project','document','task','event','concept','goal'))");
    }

    private function downPostgres(): void
    {
        DB::statement('ALTER TABLE memory_nodes DROP CONSTRAINT IF EXISTS memory_nodes_type_check');
        DB::statement("ALTER TABLE memory_nodes ADD CONSTRAINT memory_nodes_type_check CHECK (type IN ('memory','person','project','document','task','event','concept'))");
    }

    // SQLite path.

    /**
     * Recreate memory_nodes with an expanded type constraint.
     *
     * Column list must match the cumulative result of all prior migrations:
     *   2026_03_12_000001 - id, user_id, session_id, type, sensitivity, label,
     *                       content, tags, confidence, source, metadata, timestamps
     *   2026_03_12_000003 - + access_count, last_accessed_at
     *   2026_03_16_000001 - + consolidated_at
     */
    private function upSqlite(): void
    {
        DB::statement('PRAGMA foreign_keys = OFF');

        DB::statement("
            CREATE TABLE memory_nodes_new (
                id               VARCHAR(36)  NOT NULL,
                user_id          VARCHAR(255) NOT NULL,
                session_id       VARCHAR(255) NULL,
                type             VARCHAR(255) NOT NULL CHECK (type IN ('memory','person','project','document','task','event','concept','goal')),
                sensitivity      VARCHAR(255) NOT NULL DEFAULT 'public' CHECK (sensitivity IN ('public','private','sensitive')),
                label            VARCHAR(120) NOT NULL,
                content          TEXT         NOT NULL,
                tags             TEXT         NULL,
                confidence       FLOAT        NOT NULL DEFAULT 1,
                source           VARCHAR(32)  NOT NULL DEFAULT 'chat',
                metadata         TEXT         NULL,
                access_count     INTEGER      NOT NULL DEFAULT 0,
                last_accessed_at DATETIME     NULL,
                consolidated_at  DATETIME     NULL,
                created_at       DATETIME     NULL,
                updated_at       DATETIME     NULL,
                PRIMARY KEY (id)
            )
        ");

        DB::statement("
            INSERT INTO memory_nodes_new
                (id, user_id, session_id, type, sensitivity, label, content, tags,
                 confidence, source, metadata, access_count, last_accessed_at,
                 consolidated_at, created_at, updated_at)
            SELECT id, user_id, session_id, type, sensitivity, label, content, tags,
                   confidence, source, metadata, access_count, last_accessed_at,
                   consolidated_at, created_at, updated_at
            FROM memory_nodes
        ");

        DB::statement('DROP TABLE memory_nodes');
        DB::statement('ALTER TABLE memory_nodes_new RENAME TO memory_nodes');

        DB::statement('CREATE INDEX memory_nodes_user_id_index ON memory_nodes (user_id)');
        DB::statement('CREATE INDEX memory_nodes_session_id_index ON memory_nodes (session_id)');

        DB::statement('PRAGMA foreign_keys = ON');
    }

    private function downSqlite(): void
    {
        DB::statement('PRAGMA foreign_keys = OFF');

        DB::statement("
            CREATE TABLE memory_nodes_new (
                id               VARCHAR(36)  NOT NULL,
                user_id          VARCHAR(255) NOT NULL,
                session_id       VARCHAR(255) NULL,
                type             VARCHAR(255) NOT NULL CHECK (type IN ('memory','person','project','document','task','event','concept')),
                sensitivity      VARCHAR(255) NOT NULL DEFAULT 'public' CHECK (sensitivity IN ('public','private','sensitive')),
                label            VARCHAR(120) NOT NULL,
                content          TEXT         NOT NULL,
                tags             TEXT         NULL,
                confidence       FLOAT        NOT NULL DEFAULT 1,
                source           VARCHAR(32)  NOT NULL DEFAULT 'chat',
                metadata         TEXT         NULL,
                access_count     INTEGER      NOT NULL DEFAULT 0,
                last_accessed_at DATETIME     NULL,
                consolidated_at  DATETIME     NULL,
                created_at       DATETIME     NULL,
                updated_at       DATETIME     NULL,
                PRIMARY KEY (id)
            )
        ");

        DB::statement("
            INSERT INTO memory_nodes_new
                (id, user_id, session_id, type, sensitivity, label, content, tags,
                 confidence, source, metadata, access_count, last_accessed_at,
                 consolidated_at, created_at, updated_at)
            SELECT id, user_id, session_id, type, sensitivity, label, content, tags,
                   confidence, source, metadata, access_count, last_accessed_at,
                   consolidated_at, created_at, updated_at
            FROM memory_nodes
            WHERE type != 'goal'
        ");

        DB::statement('DROP TABLE memory_nodes');
        DB::statement('ALTER TABLE memory_nodes_new RENAME TO memory_nodes');

        DB::statement('CREATE INDEX memory_nodes_user_id_index ON memory_nodes (user_id)');
        DB::statement('CREATE INDEX memory_nodes_session_id_index ON memory_nodes (session_id)');

        DB::statement('PRAGMA foreign_keys = ON');
    }
};
