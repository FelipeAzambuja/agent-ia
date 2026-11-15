# Agent IA

Generic AI agent in plain PHP: per-session history, pluggable memory, tools (function calling), multimodal files and **subagents with structured handoff**. Framework-agnostic — works with CodeIgniter, Laravel, plain scripts, anything running PHP 8.2+.

## Installation

```bash
composer require felipeazambuja/agent-ia
```

The chat client is [cheinisch/openrouter-php-client](https://packagist.org/packages/cheinisch/openrouter-php-client) (OpenRouter: hundreds of models, one key) behind the built-in `AgentIa\OpenRouterChatClient` adapter. Any client implementing `AgentIa\ChatClient` works — including a fake in tests.

## Basic usage

```php
use AgentIa\Agent;
use AgentIa\OpenRouterChatClient;

$client = new OpenRouterChatClient(getenv('OPENROUTER_KEY'));

$agent = new Agent($client);
$agent->setInstruction('You are a support assistant. Answer in English.');
$response = $agent->respond('Hello, I need help.');
```

With files (image, PDF, text — local path or URL):

```php
$response = $agent->respond('Analyze this chart', ['/tmp/chart.png']);
```

## Tools (function calling)

```php
$agent->setTools([
    'current_time' => [
        'description' => 'Returns the current server date and time',
        'parameters' => ['type' => 'object', 'properties' => []],
        'execute' => fn () => date('d/m/Y H:i'),
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

## Logging

Optional, PSR-3:

```php
$agent = new Agent($client, $psr3Logger);
```

Internal failures (status assessment, topic selection, memory management, tool error) are logged and never crash the turn.

## Tests

```bash
composer install
composer test
```

The unit tests are offline: the `FakeChatClient` scripts the LLM responses, so nothing touches the network or consumes credits.

### Integration tests (real OpenRouter)

`tests/OpenRouterTest.php` runs the agent — plain turns, tool calling and subagent delegation — against the real OpenRouter API. The tests are skipped unless `OPENROUTER_KEY` is set, so the default suite above stays offline-safe (useful in CI).

```bash
cp .env.example .env   # then set your key (https://openrouter.ai/settings/keys)
composer test-integration
```

- The suite loads the project `.env` on its own (a tiny parser in `tests/Pest.php`, no phpdotenv dependency); variables already present in the real environment win over the file.
- The defaults in `.env.example` are free OpenRouter models, so the tests cost nothing — mind the free-tier rate limits (50 requests/day without a credit balance, 1000/day with one). Set `OPENROUTER_MODEL` / `OPENROUTER_LIGHT_MODEL` to test any other slug.
- With DDEV: `ddev composer test-integration`. The `.env` file is part of the project, which is mounted into the container — no extra DDEV configuration is needed. `ddev dotenv set .env --openrouter-key=...` writes the key without opening an editor.
- `.env` is gitignored; `.env.example` is the committed template.

## License

MIT
