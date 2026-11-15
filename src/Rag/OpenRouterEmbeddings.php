<?php

namespace AgentIa\Rag;

use GuzzleHttp\Client as HttpClient;
use RuntimeException;

/**
 * EmbeddingsClient over the OpenRouter embeddings endpoint
 * (POST /api/v1/embeddings, OpenAI-compatible), which the
 * cheinisch/openrouter-php-client does not expose.
 *
 * Usage:
 *   $embeddings = new OpenRouterEmbeddings(getenv('OPENROUTER_KEY'));
 *   $vectors = $embeddings->embed(['chunk one', 'chunk two']);
 */
class OpenRouterEmbeddings implements EmbeddingsClient
{
    private HttpClient $http;

    public function __construct(
        string $apiKey,
        private string $model = 'openai/text-embedding-3-small',
        private ?int $dimensions = 1536,
        array $headers = [],
    ) {
        $this->http = new HttpClient([
            'base_uri' => 'https://openrouter.ai/api/v1/',
            'headers' => array_merge([
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type' => 'application/json',
            ], $headers),
        ]);
    }

    public function embed(array $texts, string $type = 'search_document'): array
    {
        $body = ['model' => $this->model, 'input' => array_values($texts), 'input_type' => $type];
        if ($this->dimensions !== null) $body['dimensions'] = $this->dimensions;
        $response = $this->http->post('embeddings', ['json' => $body]);
        $payload = json_decode((string) $response->getBody(), true);
        if (!is_array($payload['data'] ?? null)) {
            throw new RuntimeException('OpenRouter embeddings: unexpected response.');
        }
        $vectors = [];
        foreach ($payload['data'] as $item) $vectors[] = array_map('floatval', $item['embedding']);
        return $vectors;
    }
}
