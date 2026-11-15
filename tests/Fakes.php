<?php

use AgentIa\ChatClient;

/**
 * Fake ChatClient with scripted responses: each chatEx consumes the next
 * one from the queue; structuredChat likewise. Records calls for assertions.
 */
class FakeChatClient implements ChatClient
{
    public array $chatCalls = [];
    public array $structuredCalls = [];

    /** @param array<int, array> $chatResponses @param array<int, array> $structuredResponses */
    public function __construct(private array $chatResponses = [], private array $structuredResponses = []) {}

    public function chatEx(array $messages, string $model, array $options = []): array
    {
        $this->chatCalls[] = ['messages' => $messages, 'model' => $model, 'options' => $options];
        return array_shift($this->chatResponses) ?? ['content' => ''];
    }

    public function structuredChat(array $messages, array $schema, string $model, array $options = []): array
    {
        $this->structuredCalls[] = ['messages' => $messages, 'schema' => $schema, 'model' => $model];
        return array_shift($this->structuredResponses) ?? [];
    }
}

/** Array-backed memory backend, to prove persistence across instances. */
class FakeMemoryBackend implements AgentIa\Memory\MemoryBackend
{
    public array $sessions = [];

    public function load(string $sessionId): array
    {
        return $this->sessions[$sessionId] ?? [];
    }

    public function save(string $sessionId, string $role, string $content): void
    {
        $this->sessions[$sessionId][] = ['role' => $role, 'content' => $content];
    }
}

/** Subagent double that records what it receives and returns a fixed reply. */
class FakeSubAgent extends AgentIa\Agent
{
    public array $received = [];

    public function __construct(private string $reply = 'subagent response') {}

    public function respond(string $message, array $files = []): string
    {
        $this->received[] = $message;
        return $this->reply;
    }
}

/**
 * Deterministic EmbeddingsClient: bag of words hashed into 16
 * dimensions, so texts that share words have real cosine similarity.
 */
class FakeEmbeddings implements AgentIa\Rag\EmbeddingsClient
{
    public array $calls = [];

    public function embed(array $texts, string $type = 'search_document'): array
    {
        $this->calls[] = ['texts' => $texts, 'type' => $type];
        return array_map(fn ($t) => $this->vector((string) $t), $texts);
    }

    private function vector(string $text): array
    {
        $v = array_fill(0, 16, 0.0);
        foreach (preg_split('/\W+/u', mb_strtolower($text)) ?: [] as $word) {
            if ($word !== '') $v[crc32($word) % 16] += 1.0;
        }
        return $v;
    }
}

/** In-memory VectorStore with real cosine similarity (via PdoVectorStore::cosine). */
class FakeVectorStore implements AgentIa\Rag\VectorStore
{
    public array $namespaces = [];

    public function upsert(string $namespace, array $chunks): void
    {
        foreach ($chunks as $chunk) {
            $this->namespaces[$namespace][] = $chunk + ['metadata' => []];
        }
    }

    public function similar(string $namespace, array $queryVector, int $limit): array
    {
        $scored = [];
        foreach ($this->namespaces[$namespace] ?? [] as $chunk) {
            $scored[] = [
                'text' => $chunk['text'],
                'score' => AgentIa\Rag\PdoVectorStore::cosine($queryVector, $chunk['vector']),
                'metadata' => $chunk['metadata'],
            ];
        }
        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($scored, 0, $limit);
    }

    public function count(string $namespace): int
    {
        return count($this->namespaces[$namespace] ?? []);
    }
}

/** RagSource double: records queries, returns scripted chunks. */
class FakeRagSource implements AgentIa\Rag\RagSource
{
    public array $queries = [];

    public function __construct(
        private string $name = 'docs',
        private string $description = 'Test knowledge base',
        private array $results = [],
    ) {}

    public function name(): string { return $this->name; }

    public function description(): string { return $this->description; }

    public function search(string $query, int $limit): array
    {
        $this->queries[] = ['query' => $query, 'limit' => $limit];
        return array_slice($this->results, 0, $limit);
    }
}
