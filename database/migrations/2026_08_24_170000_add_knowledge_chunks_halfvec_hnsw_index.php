<?php

use App\Support\KnowledgeChunkAnnContract;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql'
            || ! Schema::hasTable('knowledge_chunks')
            || ! Schema::hasColumn('knowledge_chunks', 'embedding_vector')) {
            return;
        }

        $hasHalfvec = DB::selectOne("SELECT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'halfvec') AS ok");
        if (! $hasHalfvec || ! (bool) $hasHalfvec->ok) {
            throw new RuntimeException('Cannot index knowledge_chunks.embedding_vector: pgvector halfvec is not installed.');
        }

        DB::statement(KnowledgeChunkAnnContract::createIndexSql());
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.KnowledgeChunkAnnContract::INDEX_NAME);
    }
};
