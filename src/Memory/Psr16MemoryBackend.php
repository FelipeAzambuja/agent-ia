<?php

namespace AgenteIa\Memory;

use Psr\SimpleCache\CacheInterface;

/**
 * MemoryBackend sobre um cache PSR-16: o histórico da sessão fica em uma
 * chave, com uma mensagem acrescentada por gravar() e TTL renovado a cada
 * turno. Simples e rápido; o histórico some quando o TTL expira.
 */
class Psr16MemoryBackend implements MemoryBackend
{
    private const TTL = 86400;

    public function __construct(private CacheInterface $cache, private int $ttl = self::TTL) {}

    public function carregar(string $sessionId): array
    {
        return $this->cache->get('agente_' . $sessionId, []);
    }

    public function gravar(string $sessionId, string $papel, string $conteudo): void
    {
        $chave = 'agente_' . $sessionId;
        $historico = $this->cache->get($chave, []);
        $historico[] = ['role' => $papel, 'content' => $conteudo];
        $this->cache->set($chave, $historico, $this->ttl);
    }
}
