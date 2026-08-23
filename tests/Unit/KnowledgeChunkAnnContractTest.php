<?php

namespace Tests\Unit;

use App\Support\KnowledgeChunkAnnContract;
use Tests\TestCase;

class KnowledgeChunkAnnContractTest extends TestCase
{
    public function test_ann_contract_uses_a_3072_dimension_halfvec_cosine_expression(): void
    {
        $this->assertTrue(class_exists(KnowledgeChunkAnnContract::class));
        $this->assertSame(
            'embedding_vector::halfvec(3072) <=> CAST(? AS halfvec(3072))',
            KnowledgeChunkAnnContract::distanceExpression(),
        );
    }

    public function test_ann_contract_builds_a_concurrent_partial_hnsw_index(): void
    {
        $this->assertTrue(class_exists(KnowledgeChunkAnnContract::class));
        $this->assertSame(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS knowledge_chunks_embedding_halfvec_hnsw '
            .'ON knowledge_chunks USING hnsw ((embedding_vector::halfvec(3072)) halfvec_cosine_ops) '
            .'WHERE embedding_vector IS NOT NULL',
            KnowledgeChunkAnnContract::createIndexSql(),
        );
    }

    public function test_ann_index_migration_runs_outside_a_database_transaction(): void
    {
        $path = database_path('migrations/2026_08_24_170000_add_knowledge_chunks_halfvec_hnsw_index.php');

        $this->assertFileExists($path);
        $migration = require $path;

        $this->assertFalse($migration->withinTransaction);
    }
}
