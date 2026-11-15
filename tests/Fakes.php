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
