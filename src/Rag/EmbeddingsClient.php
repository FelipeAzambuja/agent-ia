<?php

namespace AgentIa\Rag;

/**
 * Embedding generator consumed by the RAG components. The reference
 * implementation is OpenRouterEmbeddings (POST /api/v1/embeddings);
 * any client that turns texts into vectors works — including a fake
 * in tests.
 */
interface EmbeddingsClient
{
    /**
     * Embeds texts, returning one vector per input, in the same order.
     *
     * @param array<int, string> $texts
     * @param string $type 'search_document' when indexing, 'search_query'
     *                     when querying (providers use this to tune the vectors)
     * @return array<int, float[]>
     */
    public function embed(array $texts, string $type = 'search_document'): array;
}
