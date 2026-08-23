<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasTable('knowledge_chunks')) {
            return;
        }

        if (Schema::hasColumn('knowledge_chunks', 'embedding_vector')) {
            return;
        }

        $hasVector = DB::selectOne("SELECT EXISTS (SELECT 1 FROM pg_extension WHERE extname = 'vector') AS ok");
        if (! $hasVector || ! $hasVector->ok) {
            throw new RuntimeException('Cannot repair knowledge_chunks.embedding_vector: pgvector is not installed.');
        }

        DB::statement('ALTER TABLE knowledge_chunks ADD COLUMN embedding_vector vector(3072)');
    }

    public function down(): void
    {
        // This repairs a column owned by the original schema migration. Rolling back this
        // marker must not remove a column that may have existed before the repair ran.
    }
};
