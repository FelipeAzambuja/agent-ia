<?php

namespace AgentIa\Memory;

/**
 * Per-session conversational memory backend: loads a session's history
 * and saves the turn (one message at a time). Typical implementations:
 * PSR-16 cache, database via PDO, or nothing (in-process memory).
 */
interface MemoryBackend
{
    /** @return array<int, array{role:string,content:string}> */
    public function load(string $sessionId): array;

    public function save(string $sessionId, string $role, string $content): void;
}
