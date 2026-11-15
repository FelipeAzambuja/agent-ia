<?php

namespace AgentIa;

/**
 * Chat client consumed by the Agent. The reference implementation is
 * cheinisch/openrouter-php-client (chatEx + structuredChat); any client
 * with these signatures works — including a fake in tests.
 */
interface ChatClient
{
    /**
     * @param array $messages messages in OpenAI format (role/content/tool_calls...)
     * @param array $options provider options (temperature, tools, max_completion_tokens...)
     * @return array response with 'content' and, optionally, 'tool_calls'
     */
    public function chatEx(array $messages, string $model, array $options = []): array;

    /**
     * @param array $messages messages in OpenAI format
     * @param array $schema structured schema (name, strict, schema)
     * @return array decoded object according to the schema
     */
    public function structuredChat(array $messages, array $schema, string $model, array $options = []): array;
}
