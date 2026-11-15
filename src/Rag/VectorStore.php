<?php

namespace AgentIa\Rag;

/**
 * Storage of indexed chunks with their embeddings, organized by
 * namespace (one per knowledge base). Typical implementations: PDO,
 * pgvector, Qdrant... The store owns the similarity search.
 */
interface VectorStore
{
    /**
     * Indexes chunks (replacing nothing: appends). One vector per chunk,
     * in the same order.
     *
     * @param array<int, array{text:string, vector:float[], metadata?:array<string, mixed>}> $chunks
     */
    public function upsert(string $namespace, array $chunks): void;

    /**
     * Nearest chunks by cosine similarity, best first.
     *
     * @param float[] $queryVector
     * @return array<int, array{text:string, score:float, metadata:array<string, mixed>}>
     */
    public function similar(string $namespace, array $queryVector, int $limit): array;

    /** Number of indexed chunks in the namespace. */
    public function count(string $namespace): int;
}
