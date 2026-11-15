<?php

use AgenteIa\ChatClient;

/**
 * Fake de ChatClient com respostas scriptadas: cada chatEx consome a
 * próxima da fila; structuredChat idem. Grava as chamadas para asserções.
 */
class FakeChatClient implements ChatClient
{
    public array $chatCalls = [];
    public array $structuredCalls = [];

    /** @param array<int, array> $chatRespostas @param array<int, array> $structuredRespostas */
    public function __construct(private array $chatRespostas = [], private array $structuredRespostas = []) {}

    public function chatEx(array $messages, string $model, array $options = []): array
    {
        $this->chatCalls[] = ['messages' => $messages, 'model' => $model, 'options' => $options];
        return array_shift($this->chatRespostas) ?? ['content' => ''];
    }

    public function structuredChat(array $messages, array $schema, string $model, array $options = []): array
    {
        $this->structuredCalls[] = ['messages' => $messages, 'schema' => $schema, 'model' => $model];
        return array_shift($this->structuredRespostas) ?? [];
    }
}

/** Backend de memória em array, para provar persistência entre instâncias. */
class FakeMemoriaBackend implements AgenteIa\Memory\MemoryBackend
{
    public array $sessoes = [];

    public function carregar(string $sessionId): array
    {
        return $this->sessoes[$sessionId] ?? [];
    }

    public function gravar(string $sessionId, string $papel, string $conteudo): void
    {
        $this->sessoes[$sessionId][] = ['role' => $papel, 'content' => $conteudo];
    }
}

/** Subagente dublê que grava o que recebe e devolve texto fixo. */
class FakeSubAgente extends AgenteIa\Agente
{
    public array $recebido = [];

    public function __construct(private string $resposta = 'resposta do subagente') {}

    public function responder(string $mensagem, array $arquivos = []): string
    {
        $this->recebido[] = $mensagem;
        return $this->resposta;
    }
}
