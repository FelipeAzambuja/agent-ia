<?php

namespace AgentIa\Memory;

use Psr\SimpleCache\CacheInterface;

/**
 * MemoryBackend over a PSR-16 cache: the session's history lives in one
 * key, with one message appended per save() and the TTL renewed each
 * turn. Simple and fast; the history disappears when the TTL expires.
 */
class Psr16MemoryBackend implements MemoryBackend
{
    private const TTL = 86400;

    public function __construct(private CacheInterface $cache, private int $ttl = self::TTL) {}

    public function load(string $sessionId): array
    {
        return $this->cache->get('agent_' . $sessionId, []);
    }

    public function save(string $sessionId, string $role, string $content): void
    {
        $key = 'agent_' . $sessionId;
        $history = $this->cache->get($key, []);
        $history[] = ['role' => $role, 'content' => $content];
        $this->cache->set($key, $history, $this->ttl);
    }
}
