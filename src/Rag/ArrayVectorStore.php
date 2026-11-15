<?php

namespace AgentIa\Rag;

/**
 * In-memory VectorStore: chunks live in a PHP array and cosine
 * similarity is computed on the fly. No database, no setup — ideal
 * for demos, tests, CLI scripts and small corpora within a single
 * process. Everything is lost when the process ends; to persist,
 * swap for PdoVectorStore (same interface, one-line change).
 *
 * Usage:
 *   $store = new ArrayVectorStore();
 *   $store->upsert('manuals', $chunks);
 *   $store->similar('manuals', $queryVector, 5);
 */
class ArrayVectorStore implements VectorStore
{
    /** @var array<string, array<int, array{text:string, vector:float[], metadata:array<string, mixed>}>> */
    private array $namespaces = [];

    public function upsert(string $namespace, array $chunks): void
    {
        foreach ($chunks as $chunk) {
            $this->namespaces[$namespace][] = [
                'text' => (string) $chunk['text'],
                'vector' => array_map('floatval', $chunk['vector']),
                'metadata' => $chunk['metadata'] ?? [],
            ];
        }
    }

    public function similar(string $namespace, array $queryVector, int $limit): array
    {
        $scored = [];
        foreach ($this->namespaces[$namespace] ?? [] as $chunk) {
            $scored[] = [
                'text' => $chunk['text'],
                'score' => PdoVectorStore::cosine($queryVector, $chunk['vector']),
                'metadata' => $chunk['metadata'],
            ];
        }
        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($scored, 0, max(1, $limit));
    }

    public function count(string $namespace): int
    {
        return count($this->namespaces[$namespace] ?? []);
    }
}
