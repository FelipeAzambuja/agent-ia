# Agent IA

[🇧🇷 Português (pt-BR)](README.pt-BR.md) | English

Generic AI agent in plain PHP: per-session history, pluggable memory, tools (function calling), multimodal files, **subagents with structured handoff** and **autonomous RAG**. Framework-agnostic — works with CodeIgniter, Laravel, plain scripts, anything running PHP 8.2+.

**What makes the RAG autonomous:** you register knowledge bases; the agent itself decides *when* to consult them, *which* source to use and *with what query* — both a light model (before the turn) and the main model (mid-turn, via tools) can trigger retrieval. You never hardcode "if the user asks about X, search Y".

```
User message ──► Light model router ──► "needed? which sources? what query?"
                        │                        │
                        ▼                        ▼
              (not needed: skip)      VectorStore search (real embeddings)
                                                 │
                                                 ▼
                              Chunks injected as a system block
                                                 │
                                                 ▼
                    Main model ──► can also call search_<source> tools
                                   mid-turn (refine query, other sources)
                                                 │
                                                 ▼
                                          Final answer
```

## Installation

```bash
composer require felipeazambuja/agent-ia
```

Requirements: PHP 8.2+. The chat client is [cheinisch/openrouter-php-client](https://packagist.org/packages/cheinisch/openrouter-php-client) (OpenRouter: hundreds of models, one key) behind the built-in `AgentIa\OpenRouterChatClient` adapter. Any client implementing `AgentIa\ChatClient` works — including a fake in tests.

## Basic usage

```php
use AgentIa\Agent;
use AgentIa\OpenRouterChatClient;

$client = new OpenRouterChatClient(getenv('OPENROUTER_KEY'));

$agent = new Agent($client);
$agent->setInstruction('You are a support assistant. Answer in English.');
$response = $agent->respond('Hello, I need help.');
```

With files (image, PDF, text — local path or URL; files apply only to the current turn):

```php
$response = $agent->respond('Analyze this chart', ['/tmp/chart.png']);
$response = $agent->respond('Summarize this contract', ['https://example.com/contract.pdf']);
```

Models are configurable (defaults shown):

```php
$agent->model = 'openai/gpt-6-luna';          // main model: responses + tool calling
$agent->lightModel = 'google/gemini-2.5-flash'; // light model: internal decisions
$agent->options = ['temperature' => 0.4];       // passed to every chat call
```

The light model handles all internal decisions (RAG routing, topic selection, delegation status, continuity check) — keep it cheap and fast; it only needs structured-output support.

## Tools (function calling)

```php
$agent->setTools([
    'current_time' => [
        'description' => 'Returns the current server date and time',
        'parameters' => ['type' => 'object', 'properties' => []],
        'execute' => fn () => date('d/m/Y H:i'),
    ],
    'order_status' => [
        'description' => 'Returns the status of an order by number',
        'parameters' => [
            'type' => 'object',
            'properties' => ['number' => ['type' => 'string']],
            'required' => ['number'],
        ],
        'execute' => fn (array $args) => fetchStatus($args['number']),
    ],
]);
```

The agent runs the tool loop by itself (10-round limit, with a light continuity check before giving up). A tool error **goes back to the model as a tool result** — it tries another approach in the same turn instead of crashing the conversation.

## Subagents and orchestration (agent as tool)

An `Agent` can have subagents, registered as tools. The orchestrator routes the subject by the description and delegates:

```php
$orchestrator = new Agent($client);
$orchestrator->setInstruction('You are the main attendant...');

$visits = (new Agent($client))->setName('Clovis')->setInstruction('You only handle visit scheduling...');
$orchestrator->addSubAgent($visits, 'Handles calls about visit scheduling');
$orchestrator->addSubAgent(
    (new Agent($client))->setInstruction('You only handle financing...'),
    'Handles calls about financing',
);

$response = $orchestrator->respond('I want to schedule a visit.');
```

Delegation protocol:

1. **Required handoff** — when invoking a subagent, the model provides `summary`, `handled`, `not_handled` and `expected` (fields required by the tool schema). The subagent receives the full conversation transcript + the handoff.
2. **Assessed status** — a light model assesses whether the subagent met the expectation; the status comes back in the tool result.
3. **Chained fallback** — on failure, the orchestrator tries the next possible subagent; if none succeeds, it answers on its own.

The tool name is the name set via `setName()` (normalized to `[a-z0-9_]`) or an automatic `subagent_N`. Prefer subagents **without a memory backend** (stateless): context arrives complete on every invocation.

## Memory

Without a backend, the history lives only in the process. To persist it across requests, inject a backend:

```php
$agent->sessionId = 'support-123';

// PSR-16 cache (history expires with the TTL)
$agent->memory = new AgentIa\Memory\Psr16MemoryBackend($psr16Cache);

// Database via PDO (MySQL, PostgreSQL, SQLite...)
$agent->memory = new AgentIa\Memory\PdoMemoryBackend($pdo);

// Long-term topic memory: a light model decides, post-turn, whether to
// create/update topics per subject; another picks the relevant ones per turn
$agent->topicMemory = new AgentIa\Memory\PdoTopicsBackend($pdo);
```

`sessionId` is required when a backend is set. To implement your own backend, implement `AgentIa\Memory\MemoryBackend` and/or `AgentIa\Memory\TopicsBackend`.

Table schemas (also documented in the PDO classes' docblocks):

```sql
CREATE TABLE agent_memories (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(191) NOT NULL,
    role VARCHAR(16) NOT NULL,
    content TEXT NOT NULL,
    created_at DATETIME NOT NULL
);
CREATE INDEX idx_agent_memories_session ON agent_memories (session_id, id);

CREATE TABLE agent_topics (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(191) NOT NULL,
    description TEXT NOT NULL,
    messages TEXT NOT NULL,  -- JSON array of strings
    notes TEXT NOT NULL,     -- JSON array of strings
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);
CREATE INDEX idx_agent_topics_session ON agent_topics (session_id, id);
```

## RAG (autonomous retrieval)

Register knowledge bases with `addRagSource()`. They are used in two complementary ways:

1. **Pre-turn routing** — a light model decides whether the message needs retrieval, from which sources and with what (rewritten) query; the retrieved chunks are injected as a system block before the main response.
2. **Search tools** — every source is also a tool (`search_<name>`), so the main model can search again mid-turn: refine the query or consult another source. It fills in `query`, optional `limit` (1–20, default 5) and `reason` (audit of the decision).

Routing failure never blocks the response — the turn just proceeds without RAG (the sources remain available as tools).

### Without a database (the simplest path)

The `Rag` facade bundles chunker, embeddings and store into one object, with two verbs: `add()` to index and `search()` to retrieve. By default the store is in memory (`ArrayVectorStore`) — zero setup, only the OpenRouter key is needed:

```php
use AgentIa\Rag\Rag;

$rag = new Rag(
    'manuals',                                        // name → search_manuals tool
    'Product manuals: warranties, specs...',          // shown to both models
    getenv('OPENROUTER_KEY'),                         // key or an EmbeddingsClient
);

$rag->add($manualText, ['doc' => 'x1000']);           // chunk + embed + store, right away
$rag->addFile(__DIR__ . '/docs/x800-manual.txt');     // or index a file directly

$agent->addRag($rag);                                 // done: autonomous RAG on the agent
```

There is no `save()` step: every `add()` indexes the chunks immediately. Ideal for demos, tests, CLI scripts and small corpora within a single process. Full runnable example: `examples/rag-simple.php` (`ddev exec php examples/rag-simple.php`).

### Migrating to a database

Since the facade accepts any `VectorStore`, migrating means passing a persistent store at construction — everything else stays the same:

```php
// before (in memory, the default)
$rag = new Rag('manuals', 'Product manuals...', $key);

// after (persisted: MySQL, PostgreSQL, SQLite...)
$rag = new Rag('manuals', 'Product manuals...', $key, new PdoVectorStore($pdo));
```

With the database, indexing runs once per document (or on a cron/webhook when docs change) instead of on every run.

<details>
<summary>Low-level components (fine-grained control)</summary>

The facade is sugar over independent pieces, useful when you want to control each step — custom chunking, embeddings from another provider, your own store:

```php
use AgentIa\Rag\Chunker;
use AgentIa\Rag\OpenRouterEmbeddings;
use AgentIa\Rag\PdoVectorStore;
use AgentIa\Rag\VectorRagSource;

$embeddings = new OpenRouterEmbeddings(getenv('OPENROUTER_KEY')); // default: openai/text-embedding-3-small, 1536 dims
$store = new PdoVectorStore($pdo); // vectors as JSON, cosine in PHP (small/medium corpora)
$store->migrate();                 // creates the agent_rag_chunks table if missing

$chunks = (new Chunker())->chunk($text);    // ~800 chars, ~100 overlap
$vectors = $embeddings->embed($chunks);     // batched in one API call
$store->upsert('manuals', array_map(fn ($i) => [
    'text' => $chunks[$i],
    'vector' => $vectors[$i],
    'metadata' => ['doc' => 'x1000'],
], array_keys($chunks)));

$agent->addRagSource(new VectorRagSource('manuals', 'Product manuals...', $store, $embeddings, 'manuals'));
```

</details>

### Complete example: support agent over product manuals

Real scenario (the same one exercised by the integration tests): a support agent that answers from appliance manuals indexed in MariaDB, with real OpenRouter embeddings.

```php
use AgentIa\Agent;
use AgentIa\OpenRouterChatClient;
use AgentIa\Rag\Chunker;
use AgentIa\Rag\OpenRouterEmbeddings;
use AgentIa\Rag\PdoVectorStore;
use AgentIa\Rag\VectorRagSource;

$pdo = new PDO('mysql:host=db;dbname=db;charset=utf8mb4', 'db', 'db', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

// 1. Infrastructure: embeddings + vector store (one per application)
$embeddings = new OpenRouterEmbeddings(getenv('OPENROUTER_KEY')); // default: openai/text-embedding-3-small, 1536 dims
$store = new PdoVectorStore($pdo); // vectors as JSON, cosine in PHP (small/medium corpora)
$store->migrate();                 // creates the agent_rag_chunks table if missing

// 2. Indexing (run once per document, or on a cron/webhook when docs change)
function indexDocument(PdoVectorStore $store, OpenRouterEmbeddings $embeddings,
                       string $namespace, string $text, array $metadata = []): void
{
    $chunks = (new Chunker())->chunk($text);         // ~800 chars, ~100 overlap
    $vectors = $embeddings->embed($chunks);          // batched in one API call
    $store->upsert($namespace, array_map(fn ($i) => [
        'text' => $chunks[$i],
        'vector' => $vectors[$i],
        'metadata' => $metadata,
    ], array_keys($chunks)));
}

indexDocument($store, $embeddings, 'manuals',
    file_get_contents(__DIR__ . '/docs/x1000-manual.txt'),
    ['doc' => 'x1000-manual', 'version' => '2024-11']);
indexDocument($store, $embeddings, 'manuals',
    file_get_contents(__DIR__ . '/docs/x800-manual.txt'),
    ['doc' => 'x800-manual']);

// 3. The agent: register the source and talk
$agent = new Agent(getenv('OPENROUTER_KEY'));
$agent->setInstruction('You are a support assistant for Solaris Appliances. Answer briefly, in English.');
$agent->addRagSource(new VectorRagSource(
    'manuals',                                        // name → tool search_manuals
    'Product manuals: warranties, specs, usage and troubleshooting', // shown to both models
    $store,
    $embeddings,
    'manuals',                                        // namespace in the store
));

echo $agent->respond('What is the warranty period of the X1000 vacuum?');
// → "The X1000 cordless vacuum has a three-year warranty on motor and battery."
```

What happens on that turn, under the hood:

1. The light model routes: `{needed: true, sources: ["manuals"], query: "X1000 vacuum warranty period", reason: "asks about a product warranty"}`.
2. The Agent embeds the rewritten query (`search_query` type), the store ranks chunks by cosine similarity, the top ones are injected as a system block (`[score 0.93] ...` per chunk).
3. The main model answers from the injected chunks. If the excerpts were not enough, it can call `search_manuals` itself (with its own query and `reason`) and try again — all within the same turn.

### Multiple sources

Register as many as you want; the router picks which ones to consult (and can pick several):

```php
$agent->addRagSource(new VectorRagSource('manuals', 'Product manuals...', $store, $embeddings, 'manuals'));
$agent->addRagSource(new VectorRagSource('policies', 'Refund, exchange and warranty policies...', $store, $embeddings, 'policies'));
$agent->addRagSource(new VectorRagSource('faq', 'Frequently asked questions...', $store, $embeddings, 'faq'));
```

### Custom backends

For larger corpora, implement `AgentIa\Rag\VectorStore` over pgvector, Qdrant, Meilisearch... and any `AgentIa\Rag\EmbeddingsClient`. Or skip vectors entirely with a custom `AgentIa\Rag\RagSource` that searches however you want — SQL `LIKE`, an external API, a search engine:

```php
class ApiRagSource implements AgentIa\Rag\RagSource
{
    public function name(): string { return 'tickets'; }
    public function description(): string { return 'Historical support tickets of this customer'; }
    public function search(string $query, int $limit): array
    {
        // Return chunks in the same shape: text, score, metadata
        return $this->api->searchTickets($query, $limit);
    }
}
$agent->addRagSource(new ApiRagSource());
```

### Table schema

Also in the `PdoVectorStore` docblock; `migrate()` creates it (driver-aware: MySQL, PostgreSQL, SQLite).

```sql
CREATE TABLE agent_rag_chunks (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    namespace VARCHAR(191) NOT NULL,
    content TEXT NOT NULL,
    embedding TEXT NOT NULL, -- JSON array of floats
    metadata TEXT NOT NULL,  -- JSON object
    created_at DATETIME NOT NULL
);
CREATE INDEX idx_agent_rag_namespace ON agent_rag_chunks (namespace, id);
```

> The column is `embedding`, not `vector`: `VECTOR` is a reserved word in MariaDB 11.7+.

`PdoVectorStore` loads the namespace and computes cosine similarity in PHP — simple and portable, adequate up to a few thousand chunks per namespace. For more, implement `VectorStore` over pgvector or a dedicated vector database.

## Logging

Optional, PSR-3:

```php
$agent = new Agent($client, $psr3Logger);
```

Internal failures (RAG routing, RAG search, status assessment, topic selection, memory management, tool error) are logged and never crash the turn.

## Tests

```bash
composer install
composer test
```

The unit tests are offline: the `FakeChatClient` scripts the LLM responses and the RAG components run on fakes/in-memory SQLite, so nothing touches the network or consumes credits.

### Integration tests (real API, real database)

Two suites run against real infrastructure. They are skipped when their prerequisites are missing, so the default suite stays offline-safe (useful in CI):

```bash
cp .env.example .env   # then set your key (https://openrouter.ai/settings/keys)
composer test-integration
```

| Suite | What is real | Prerequisites |
|---|---|---|
| `tests/OpenRouterTest.php` | Agent turns, tool calling and subagent delegation against the real OpenRouter API | `OPENROUTER_KEY` |
| `tests/RagIntegrationTest.php` | Real embeddings (`/api/v1/embeddings`), real MariaDB (DDEV), chunking, indexing, retrieval and the full agent loop end to end | `OPENROUTER_KEY` + database reachable |

Environment variables (loaded from `.env` by a tiny parser in `tests/Pest.php`; real environment wins):

| Variable | Purpose | Default |
|---|---|---|
| `OPENROUTER_KEY` | API key for chat and embeddings | — (required for integration) |
| `OPENROUTER_EMBEDDING_MODEL` | Embedding model for the RAG tests | `openai/text-embedding-3-small` |
| `RAG_DB_DSN` | PDO DSN for the RAG tests | `mysql:host=db;dbname=db;charset=utf8mb4` (DDEV) |
| `RAG_DB_USER` / `RAG_DB_PASSWORD` | Database credentials | `db` / `db` (DDEV) |

Notes:

- The integration tests use the provider matrix in `providers()` (`tests/OpenRouterTest.php`), so they cost only what those models charge — mind the OpenRouter free-tier rate limits (50 requests/day without a credit balance, 1000/day with one). Embeddings cost ~$0.02 per 1M tokens; a full RAG suite run is a fraction of a cent.
- With DDEV: `ddev composer test-integration`. The `.env` is part of the project, mounted into the container — no extra DDEV configuration is needed. `ddev dotenv set .env --openrouter-key=...` writes the key without opening an editor.
- `.env` is gitignored; `.env.example` is the committed template.

## Examples

All runnable CLI scripts in `examples/`, sharing a tiny bootstrap (`_bootstrap.php`: loads `.env`, checks the key). Run them with DDEV from the project root:

```bash
ddev exec php examples/basic.php "How do I reset my password?"
```

| Example | What it shows | Requires |
|---|---|---|
| `basic.php` | The smallest agent: one instruction, one turn (message via argv or interactive chat) | `OPENROUTER_KEY` |
| `tools.php` | Function calling: the model calls `current_time` and `order_status` on its own | `OPENROUTER_KEY` |
| `subagents.php` | Orchestrator delegating to specialists (visits, financing) with structured handoff | `OPENROUTER_KEY` |
| `files.php` | Multimodal turn: analyze an image, PDF or text file (path or URL via argv) | `OPENROUTER_KEY` |
| `memory.php` | Persistent memory + long-term topics over SQLite — run it twice and it remembers | `OPENROUTER_KEY` |
| `rag-simple.php` | RAG with no database: the `Rag` facade over an in-memory store | `OPENROUTER_KEY` |
| `rag-index.php` | RAG indexing side: manuals → chunks + embeddings in MariaDB (run once, or on a cron) | `OPENROUTER_KEY` + database |
| `rag-support.php` | RAG attendance side: the agent answers from what `rag-index.php` stored | `OPENROUTER_KEY` + indexed base |

`rag-index.php` and `rag-support.php` share `_manuals.php` (one PDO connection, the manual texts and one `Rag` factory) — the pattern that keeps the indexing script and the chat script in sync on name, description and namespace.

## API reference

| Class / interface | Role |
|---|---|
| `AgentIa\Agent` | The agent: history, tools, subagents, RAG, memory |
| `AgentIa\ChatClient` | Chat contract (`chatEx`, `structuredChat`) |
| `AgentIa\OpenRouterChatClient` | Reference implementation (cheinisch/openrouter-php-client) |
| `AgentIa\Rag\RagSource` | A searchable knowledge base (name, description, search) |
| `AgentIa\Rag\Rag` | High-level facade: `add()` indexes, `search()` retrieves (chunker + embeddings + store in one) |
| `AgentIa\Rag\VectorRagSource` | RagSource over a VectorStore + EmbeddingsClient |
| `AgentIa\Rag\VectorStore` | Chunk storage + similarity search (upsert, similar, count) |
| `AgentIa\Rag\PdoVectorStore` | VectorStore over PDO (JSON vectors, cosine in PHP) |
| `AgentIa\Rag\ArrayVectorStore` | In-memory VectorStore (PHP array, no database) |
| `AgentIa\Rag\EmbeddingsClient` | Text → vectors (`search_document` / `search_query`) |
| `AgentIa\Rag\OpenRouterEmbeddings` | EmbeddingsClient over OpenRouter `/api/v1/embeddings` |
| `AgentIa\Rag\Chunker` | Document → chunks (size/overlap, sentence-aware) |
| `AgentIa\Memory\MemoryBackend` | Per-session conversational history |
| `AgentIa\Memory\TopicsBackend` | Long-term topic memory |
| `AgentIa\Memory\Psr16MemoryBackend` / `PdoMemoryBackend` / `PdoTopicsBackend` | Built-in backends |

## License

MIT
