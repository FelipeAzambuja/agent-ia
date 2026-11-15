<?php

namespace AgenteIa\Memory;

/**
 * Backend de tópicos de memória de longo prazo por sessão: assuntos
 * guardados com mensagens e anotações, criados e atualizados pela
 * gerência pós-turno do Agente.
 */
interface TopicsBackend
{
    /** @return array<int, array{id:int,descricao:string,mensagens:string[],anotacoes:string[]}> */
    public function todos(string $sessionId): array;

    /** @param string[] $mensagens @param string[] $anotacoes */
    public function criar(string $sessionId, string $descricao, array $mensagens, array $anotacoes): void;

    /** @param string[] $mensagens @param string[] $anotacoes */
    public function atualizar(int $id, array $mensagens, array $anotacoes): void;
}
