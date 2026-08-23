<?php

namespace App\Support;

final class KnowledgeChunkAnnContract
{
    public const DIMENSIONS = 3072;

    public const INDEX_NAME = 'knowledge_chunks_embedding_halfvec_hnsw';

    public static function distanceExpression(): string
    {
        return 'embedding_vector::halfvec('.self::DIMENSIONS.') <=> CAST(? AS halfvec('.self::DIMENSIONS.'))';
    }

    public static function createIndexSql(): string
    {
        return 'CREATE INDEX CONCURRENTLY IF NOT EXISTS '.self::INDEX_NAME.' '
            .'ON knowledge_chunks USING hnsw ((embedding_vector::halfvec('.self::DIMENSIONS.')) halfvec_cosine_ops) '
            .'WHERE embedding_vector IS NOT NULL';
    }
}
