<?php

namespace AgentIa\Rag;

/**
 * RagSource backed by a VectorStore: embeds the query (as
 * 'search_query') and returns the nearest chunks.
 *
 * Usage:
 *   $source = new VectorRagSource('products', 'Product catalog with prices and stock', $store, $embeddings, 'products');
 *   $agent->addRagSource($source);
 */
class VectorRagSource implements RagSource
{
    public function __construct(
        private string $name,
        private string $description,
        private VectorStore $store,
        private EmbeddingsClient $embeddings,
        private string $namespace = 'default',
        private int $defaultLimit = 5,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function search(string $query, int $limit): array
    {
        $vector = $this->embeddings->embed([$query], 'search_query')[0] ?? null;
        if ($vector === null) return [];
        return $this->store->similar($this->namespace, $vector, $limit);
    }
}
