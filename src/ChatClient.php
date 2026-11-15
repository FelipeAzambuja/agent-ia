<?php

namespace AgenteIa;

/**
 * Cliente de chat que o Agente consome. A implementação de referência é
 * cheinisch/openrouter-php-client (chatEx + structuredChat); qualquer
 * cliente com estas assinaturas serve — inclusive um fake em testes.
 */
interface ChatClient
{
    /**
     * @param array $messages mensagens no formato OpenAI (role/content/tool_calls...)
     * @param array $options opções do fornecedor (temperature, tools, max_completion_tokens...)
     * @return array resposta com 'content' e, opcionalmente, 'tool_calls'
     */
    public function chatEx(array $messages, string $model, array $options = []): array;

    /**
     * @param array $messages mensagens no formato OpenAI
     * @param array $schema schema estruturado (name, strict, schema)
     * @return array objeto decodificado conforme o schema
     */
    public function structuredChat(array $messages, array $schema, string $model, array $options = []): array;
}
