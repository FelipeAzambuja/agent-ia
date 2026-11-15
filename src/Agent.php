<?php

namespace AgentIa;

use AgentIa\Memory\MemoryBackend;
use AgentIa\Memory\TopicsBackend;
use Psr\Log\LoggerInterface;

/**
 * Simple, generic AI agent: per-session history, pluggable memory
 * (in-process, PSR-16 cache, PDO or topics) and optional tools
 * (function calling). Knows nothing about domain or channel: the caller
 * defines the instruction, the tools and calls respond() per
 * conversation turn.
 *
 * Usage:
 *   $agent = new Agent($client); // $client implements AgentIa\ChatClient
 *   $agent->sessionId = 'support-123'; // required when a memory backend is set
 *   $agent->memory = new Psr16MemoryBackend($cache); // or PdoMemoryBackend($pdo)
 *   $agent->topicMemory = new PdoTopicsBackend($pdo); // long-term memory
 *   $agent->setInstruction('You are ...');
 *   $agent->setTools(['name' => ['description' => ..., 'parameters' => schema, 'execute' => fn]]);
 *   $response = $agent->respond('user message');
 *   $response = $agent->respond('analyze', ['/tmp/chart.png', 'https://.../doc.pdf']);
 *
 * Orchestration: an Agent can have subagents, registered as tools
 * ("agent as tool" pattern). The orchestrator model routes the subject
 * by the description and the tool delegates to the subagent's
 * respond(), which receives the full conversation transcript plus the
 * handoff: summary, what the orchestrator already handled, what it
 * could not handle and what it expects the subagent to handle
 * (required fields in the tool schema). A light model assesses whether
 * the subagent succeeded; the status comes back in the tool result and,
 * on failure, the orchestrator tries the next possible subagent — if
 * none succeeds, it answers on its own. Prefer subagents without a
 * memory backend (stateless): context arrives complete on every
 * invocation and persistent memory would duplicate the transcript.
 * Call addSubAgent() after setTools(), which replaces the tools array.
 *   $agent->addSubAgent((new Agent($client))->setName('Clovis')->setInstruction('...'), 'Handles calls about subject X');
 */
class Agent
{
    /** Session identifier; required when a memory backend is set. */
    public string $sessionId = '';
    /** Instance name; used as the tool name when the agent becomes a subagent. */
    public string $name = '';
    /** Main model for responses. */
    public string $model = 'openai/gpt-4o-mini';
    /** Light model for internal decisions (continuity, topics, delegation status). */
    public string $lightModel = 'google/gemini-2.5-flash';
    /** Conversational memory backend; null = history lives only in the process. */
    public ?MemoryBackend $memory = null;
    /** Long-term topic memory backend; null = disabled. */
    public ?TopicsBackend $topicMemory = null;
    /** Extra options passed to chatEx (temperature, max_completion_tokens...). */
    public array $options = [];

    private string $instruction = '';
    /** @var array<string, array{description:string,parameters:array,execute:callable}> */
    private array $tools = [];
    /** @var array<int, array{role:string,content:string}> */
    private array $history = [];
    /** @var array<int, array{id:int,description:string,messages:string[],notes:string[]}> */
    private array $topics = [];
    private bool $loaded = false;

    private const MAX_TOOL_ROUNDS = 10;
    private const MAX_HISTORY = 50;

    public function __construct(
        private ChatClient $client,
        private ?LoggerInterface $logger = null,
    ) {}

    public function setInstruction(string $instruction): static
    {
        $this->instruction = $instruction;
        return $this;
    }

    /**
     * @param array $tools name => ['description' => string, 'parameters' => JSON schema,
     *                            'execute' => callable(array $args): mixed]
     */
    public function setTools(array $tools): static
    {
        $this->tools = $tools;
        return $this;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Registers a subagent as a function-calling tool ("agent as tool").
     * The call requires the handoff (summary, what the orchestrator did
     * and did not handle and what it expects from the subagent); the
     * result comes back with the assessed delegation status and the
     * fallback guidance.
     */
    public function addSubAgent(Agent $agent, string $description): static
    {
        $name = $this->toolName($agent->name) ?: 'subagent_' . (count($this->tools) + 1);
        $this->tools[$name] = [
            'description' => $description . ' When delegating, fill in the handoff with real conversation content: summary of what the client wants, what you already handled, what you could not handle and what you expect the subagent to handle.',
            'parameters' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'summary' => ['type' => 'string'],
                    'handled' => ['type' => 'string'],
                    'not_handled' => ['type' => 'string'],
                    'expected' => ['type' => 'string'],
                ],
                'required' => ['summary', 'handled', 'not_handled', 'expected'],
            ],
            'execute' => function (array $args) use ($agent): string {
                $response = $agent->respond($this->transcript() . "\n\n" . $this->handoff($args));
                $status = $this->delegationStatus((string) ($args['expected'] ?? ''), $response);
                return $response . "\n\n[Delegation status: the subagent "
                    . ($status ? 'did handle what was expected.' : 'did NOT handle what was expected. '
                        . 'If another possible subagent exists, delegate to it; if none can, answer the user yourself.') . ']';
            },
        ];
        return $this;
    }

    /** Valid tool name ([a-zA-Z0-9_-]): lowercase, no accents, the rest becomes _. */
    private function toolName(string $name): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtolower(trim($name))) ?: '';
        $name = trim((string) preg_replace('/[^a-z0-9]+/', '_', $ascii), '_');
        return mb_substr($name, 0, 64);
    }

    /** Conversation history as a textual transcript, to hand to subagents. */
    private function transcript(): string
    {
        $lines = array_map(fn ($m) => ucfirst($m['role']) . ': ' . $m['content'], $this->history);
        return "Conversation so far:\n" . implode("\n", $lines);
    }

    /** Handoff block sent to the subagent along with the transcript. */
    private function handoff(array $args): string
    {
        return "Handoff from the orchestrator agent:\n"
            . 'Summary: ' . (string) ($args['summary'] ?? '') . "\n"
            . 'What it already handled: ' . (string) ($args['handled'] ?? '') . "\n"
            . 'What it could not handle: ' . (string) ($args['not_handled'] ?? '') . "\n"
            . 'What it expects the subagent to handle: ' . (string) ($args['expected'] ?? '');
    }

    /**
     * Light model assesses whether the subagent's response met the
     * expectation. Assessment failure does not block the flow: assumes
     * success.
     */
    protected function delegationStatus(string $expected, string $response): bool
    {
        try {
            $decision = $this->client->structuredChat(
                [
                    ['role' => 'system', 'content' => "You assess whether a subagent's response fulfilled what the orchestrator agent expected when delegating. Consider it fulfilled when the response concretely solves or advances the expectation; mark false only when the subagent clearly failed to resolve the request."],
                    ['role' => 'user', 'content' => "Expected by the orchestrator:\n{$expected}\n\nSubagent response:\n{$response}\n\nDid the subagent fulfill what was expected?"],
                ],
                [
                    'name' => 'delegation_status',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => ['fulfilled' => ['type' => 'boolean']],
                        'required' => ['fulfilled'],
                    ],
                ],
                $this->lightModel,
            );
        } catch (\Throwable $e) {
            $this->logger?->error('Agent: delegation assessment failed: {error}', ['error' => $e->getMessage()]);
            return true;
        }
        return (bool) ($decision['fulfilled'] ?? true);
    }

    /** Sends the user message (with optional files) and returns the response text. */
    public function respond(string $message, array $files = []): string
    {
        $this->loadHistory();
        if (trim($message) !== '' || $files !== []) {
            $this->history[] = ['role' => 'user', 'content' => trim($message) !== '' ? $message : '[files attached]'];
        }

        $messages = $this->instruction !== '' ? [['role' => 'system', 'content' => $this->instruction]] : [];
        if ($this->topicMemory) {
            $block = $this->topicsBlock($message);
            if ($block !== '') $messages[] = ['role' => 'system', 'content' => $block];
        }
        $messages = array_merge($messages, array_slice($this->history, -self::MAX_HISTORY));
        // Files apply only to the current turn: the last user message
        // (plain text in the history) becomes multimodal content with parts.
        if ($files !== []) {
            $messages[array_key_last($messages)]['content'] = $this->userParts($message, $files);
        }

        $rounds = 0;
        while (true) {
            $response = $this->client->chatEx($messages, $this->model, $this->options + $this->toolOptions());
            $toolCalls = $response['tool_calls'] ?? [];
            if ($toolCalls === []) break;
            // Past the limit: a light model decides whether one more round
            // of tools is still needed; without approval, the loop ends.
            if (++$rounds > self::MAX_TOOL_ROUNDS && !$this->canContinue($messages, $toolCalls)) break;
            $messages[] = ['role' => 'assistant', 'content' => $response['content'] ?: null, 'tool_calls' => $toolCalls];
            foreach ($toolCalls as $call) {
                $name = (string) ($call['function']['name'] ?? '');
                $args = json_decode((string) ($call['function']['arguments'] ?? ''), true) ?: [];
                // Tool errors go back to the model as a tool result, so it
                // can try another approach in the same turn instead of
                // crashing the conversation.
                try {
                    $output = ($this->tools[$name]['execute'] ?? static fn () => '')($args);
                } catch (\Throwable $e) {
                    $this->logger?->error('Agent: tool {name} failed: {error}', ['name' => $name, 'error' => $e->getMessage()]);
                    $output = 'Error executing tool ' . $name . ': ' . $e->getMessage();
                }
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => (string) ($call['id'] ?? ''),
                    'name' => $name,
                    'content' => is_string($output) ? $output : json_encode($output, JSON_UNESCAPED_UNICODE),
                ];
            }
        }

        $text = trim((string) ($response['content'] ?? ''));
        if ($text === '' && $toolCalls !== []) {
            $this->logger?->warning('Agent: tool loop ended without a textual response (session {session}).', ['session' => $this->sessionId]);
        }
        $this->history[] = ['role' => 'assistant', 'content' => $text];
        $this->persist($message, $text);
        return $text;
    }

    /** Light check: is one more round of tools still needed? Failure closes the loop. */
    private function canContinue(array $messages, array $toolCalls): bool
    {
        $requests = array_map(
            fn ($call) => ($call['function']['name'] ?? '') . '(' . (string) ($call['function']['arguments'] ?? '') . ')',
            $toolCalls,
        );
        try {
            $decision = $this->client->structuredChat(
                [
                    ['role' => 'system', 'content' => 'You check whether a conversational agent needs one more round of tool calls before answering the user. Consider the recent history and the tools requested in the last round. Do not invent need: if the history is already enough to answer, continue is false.'],
                    ['role' => 'user', 'content' => "Recent history:\n" . json_encode(array_slice($messages, -6), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\nTools requested now:\n" . implode("\n", $requests) . "\n\nAre these tools still necessary to answer the user?"],
                ],
                [
                    'name' => 'continuity_check',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => ['continue' => ['type' => 'boolean']],
                        'required' => ['continue'],
                    ],
                ],
                $this->lightModel,
            );
        } catch (\Throwable $e) {
            $this->logger?->error('Agent: continuity check failed: {error}', ['error' => $e->getMessage()]);
            return false;
        }
        return (bool) ($decision['continue'] ?? false);
    }

    /**
     * Multimodal parts of the message: text + one file per item in
     * $files (local path or http(s) URL). Images become image_url, PDFs
     * become file (base64), other types are read as text.
     */
    private function userParts(string $message, array $files): array
    {
        $parts = [];
        if (trim($message) !== '') $parts[] = ['type' => 'text', 'text' => $message];
        foreach ($files as $file) $parts = array_merge($parts, $this->filePart((string) $file));
        return $parts;
    }

    private function filePart(string $file): array
    {
        $ext = strtolower(pathinfo(parse_url($file, PHP_URL_PATH) ?? $file, PATHINFO_EXTENSION));
        $remote = (bool) preg_match('#^https?://#i', $file);
        $content = file_get_contents($file);
        if ($content === false) throw new \InvalidArgumentException("File could not be read: {$file}");
        if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true)) {
            $mimes = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'];
            $url = $remote ? $file : 'data:' . $mimes[$ext] . ';base64,' . base64_encode($content);
            return [['type' => 'image_url', 'image_url' => ['url' => $url]]];
        }
        if ($ext === 'pdf') {
            return [['type' => 'file', 'file' => [
                'filename' => basename($file),
                'file_data' => 'data:application/pdf;base64,' . base64_encode($content),
            ]]];
        }
        return [['type' => 'text', 'text' => "File " . basename($file) . ":\n{$content}"]];
    }

    private function toolOptions(): array
    {
        if ($this->tools === []) return [];
        $defs = [];
        foreach ($this->tools as $name => $tool) {
            $defs[] = ['type' => 'function', 'function' => [
                'name' => $name,
                'description' => (string) ($tool['description'] ?? ''),
                'parameters' => $tool['parameters'] ?? ['type' => 'object', 'properties' => []],
            ]];
        }
        return ['tools' => $defs, 'tool_choice' => 'auto'];
    }

    private function loadHistory(): void
    {
        if ($this->loaded || (!$this->memory && !$this->topicMemory)) {
            $this->loaded = true;
            return;
        }
        if ($this->sessionId === '') throw new \LogicException('sessionId is required when a memory backend is set.');
        if ($this->memory) $this->history = $this->memory->load($this->sessionId);
        if ($this->topicMemory) $this->loadTopics();
        $this->loaded = true;
    }

    /** Persists the turn (user + final response) to the memory backend. */
    private function persist(string $message, string $text): void
    {
        if (!$this->memory && !$this->topicMemory) return;
        if ($this->memory) {
            foreach ([['user', $message], ['assistant', $text]] as [$role, $content]) {
                if (trim($content) === '') continue;
                $this->memory->save($this->sessionId, $role, $content);
            }
        }
        if ($this->topicMemory) $this->manageMemory($message, $text);
    }

    private function loadTopics(): void
    {
        $this->topics = $this->topicMemory->all($this->sessionId);
    }

    /**
     * Light model picks the topics relevant to the current message.
     * Returns ids; null on failure (the caller uses all).
     */
    private function selectTopics(string $message): ?array
    {
        $list = array_map(fn ($t) => [
            'id' => $t['id'],
            'description' => $t['description'],
            'notes' => $t['notes'],
        ], $this->topics);
        try {
            $decision = $this->client->structuredChat(
                [
                    ['role' => 'system', 'content' => "You choose which topics from a session's memory are relevant to answering the user's current message. Return only the needed ids; an empty list if no topic helps."],
                    ['role' => 'user', 'content' => "Current message:\n{$message}\n\nTopics:\n" . json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
                ],
                [
                    'name' => 'topic_selection',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => ['ids' => ['type' => 'array', 'items' => ['type' => 'integer']]],
                        'required' => ['ids'],
                    ],
                ],
                $this->lightModel,
            );
        } catch (\Throwable $e) {
            $this->logger?->error('Agent: topic selection failed: {error}', ['error' => $e->getMessage()]);
            return null;
        }
        $valid = array_column($this->topics, 'id');
        return array_values(array_intersect($decision['ids'] ?? [], $valid));
    }

    /** Context block with the selected topics (all of them if selection fails). */
    private function topicsBlock(string $message): string
    {
        if ($this->topics === []) return '';
        $ids = $this->selectTopics($message);
        $part = $ids === null
            ? $this->topics
            : array_values(array_filter($this->topics, fn ($t) => in_array($t['id'], $ids, true)));
        if ($part === []) return '';
        $blocks = array_map(fn ($t) => "## {$t['description']}\n"
            . 'Notes: ' . (implode(' | ', $t['notes']) ?: '(none)') . "\n"
            . "Messages:\n" . implode("\n", $t['messages']), $part);
        return "Memory of previous subjects in this session:\n\n" . implode("\n\n", $blocks);
    }

    /**
     * Light model decides, post-turn, what the topic memory should keep:
     * create a new topic, update an existing one or nothing. Failure does
     * not affect the response — it just does not save.
     */
    private function manageMemory(string $message, string $text): void
    {
        $list = array_map(fn ($t) => [
            'id' => $t['id'],
            'description' => $t['description'],
            'notes' => $t['notes'],
        ], $this->topics);
        try {
            $decision = $this->client->structuredChat(
                [
                    ['role' => 'system', 'content' => 'You manage the long-term memory of a conversational agent, organized into topics by subject. For each turn, decide the operations: "create" a new topic when a new subject worth remembering later comes up; "update" an existing topic (provide the id) by appending messages and/or notes; "nothing" when there is nothing worth remembering. Prefer updating over creating duplicate topics. Notes are short factual sentences; messages are literal copies of the turn relevant to the subject. Do not memorize trivia (greetings, generic replies).'],
                    ['role' => 'user', 'content' => "User message:\n{$message}\n\nAgent response:\n{$text}\n\nCurrent topics:\n" . json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
                ],
                [
                    'name' => 'memory_management',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => ['operations' => ['type' => 'array', 'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'properties' => [
                                'action' => ['type' => 'string', 'enum' => ['create', 'update', 'nothing']],
                                'id' => ['type' => 'integer'],
                                'description' => ['type' => 'string'],
                                'messages' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'notes' => ['type' => 'array', 'items' => ['type' => 'string']],
                            ],
                            'required' => ['action', 'id', 'description', 'messages', 'notes'],
                        ]]],
                        'required' => ['operations'],
                    ],
                ],
                $this->lightModel,
            );
        } catch (\Throwable $e) {
            $this->logger?->error('Agent: memory management failed: {error}', ['error' => $e->getMessage()]);
            return;
        }
        foreach ($decision['operations'] ?? [] as $op) {
            $messages = array_values(array_filter((array) ($op['messages'] ?? [])));
            $notes = array_values(array_filter((array) ($op['notes'] ?? [])));
            if (($op['action'] ?? '') === 'create') {
                if (trim((string) ($op['description'] ?? '')) === '') continue;
                $this->topicMemory->create($this->sessionId, (string) $op['description'], $messages, $notes);
                $this->loadTopics();
            } elseif (($op['action'] ?? '') === 'update') {
                $index = array_search((int) ($op['id'] ?? 0), array_column($this->topics, 'id'), true);
                if ($index === false) continue;
                $topic = $this->topics[$index];
                $topic['messages'] = array_merge($topic['messages'], $messages);
                $topic['notes'] = array_merge($topic['notes'], $notes);
                $this->topicMemory->update($topic['id'], $topic['messages'], $topic['notes']);
                $this->topics[$index] = $topic;
            }
        }
    }
}
