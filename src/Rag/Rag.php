<?php

namespace AgentIa\Rag;

/**
 * High-level RAG facade: bundles a Chunker, an EmbeddingsClient and a
 * VectorStore behind two verbs — add() to index, search() to retrieve.
 * It is itself a RagSource, so an Agent consumes it directly:
 *
 *   $rag = new Rag('manuals', 'Product manuals...', getenv('OPENROUTER_KEY'));
 *   $rag->add($manualText, ['doc' => 'x1000']);   // chunk + embed + store
 *   $agent->addRag($rag);
 *
 * By default the store is in memory (ArrayVectorStore): zero setup,
 * nothing persists. To persist, pass a store:
 *
 *   $rag = new Rag('manuals', 'Product manuals...', $key, new PdoVectorStore($pdo));
 *
 * Indexing is immediate — add() already stores the chunks, so there is
 * no save() step to forget.
 */
class Rag implements RagSource
{
    private EmbeddingsClient $embeddings;
    private VectorStore $store;
    private string $namespace;

    /**
     * @param EmbeddingsClient|string $embeddings an EmbeddingsClient or an
     *        OpenRouter API key (creates an OpenRouterEmbeddings with defaults)
     * @param VectorStore|null $store defaults to an in-memory ArrayVectorStore
     * @param string|null $namespace store namespace; defaults to the source name
     */
    public function __construct(
        private string $name,
        private string $description,
        EmbeddingsClient|string $embeddings,
        ?VectorStore $store = null,
        ?string $namespace = null,
    ) {
        $this->embeddings = is_string($embeddings) ? new OpenRouterEmbeddings($embeddings) : $embeddings;
        $this->store = $store ?? new ArrayVectorStore();
        $this->namespace = $namespace ?? $name;
    }

    /**
     * Indexes a document: chunks it, embeds the chunks (one batched API
     * call) and stores them. Returns the number of chunks indexed.
     */
    public function add(string $text, array $metadata = []): int
    {
        $chunks = (new Chunker())->chunk($text);
        if ($chunks === []) return 0;
        $vectors = $this->embeddings->embed($chunks);
        $this->store->upsert($this->namespace, array_map(fn ($i) => [
            'text' => $chunks[$i],
            'vector' => $vectors[$i],
            'metadata' => $metadata,
        ], array_keys($chunks)));
        return count($chunks);
    }

    /** Indexes a local file (text-like content). Returns the number of chunks. */
    public function addFile(string $path, array $metadata = []): int
    {
        $metadata += ['doc' => basename($path)];
        return $this->add((string) file_get_contents($path), $metadata);
    }

    /** Number of chunks indexed in this namespace so far. */
    public function count(): int
    {
        return $this->store->count($this->namespace);
    }

    /**
     * Creates the backing table when the store supports it (e.g.
     * PdoVectorStore). No-op for stores without a schema (in memory).
     */
    public function migrate(): void
    {
        if (method_exists($this->store, 'migrate')) $this->store->migrate();
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    /** Embeds the query (as 'search_query') and returns the nearest chunks. */
    public function search(string $query, int $limit): array
    {
        $vector = $this->embeddings->embed([$query], 'search_query')[0] ?? null;
        if ($vector === null) return [];
        return $this->store->similar($this->namespace, $vector, $limit);
    }
}
