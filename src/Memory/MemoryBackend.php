<?php

namespace AgenteIa\Memory;

/**
 * Backend de memória conversacional por sessão: carrega o histórico de
 * uma sessão e grava o turno (uma mensagem por vez). Implementações
 * típicas: cache PSR-16, banco via PDO, ou nada (memória do processo).
 */
interface MemoryBackend
{
    /** @return array<int, array{role:string,content:string}> */
    public function carregar(string $sessionId): array;

    public function gravar(string $sessionId, string $papel, string $conteudo): void;
}
