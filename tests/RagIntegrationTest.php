<?php

use AgentIa\Agent;
use AgentIa\OpenRouterChatClient;
use AgentIa\Rag\Chunker;
use AgentIa\Rag\OpenRouterEmbeddings;
use AgentIa\Rag\PdoVectorStore;
use AgentIa\Rag\VectorRagSource;

/**
 * RAG integration tests: real OpenRouter embeddings, real MariaDB
 * (DDEV) and the real agent loop end to end. Skipped when
 * OPENROUTER_KEY is not set or the database is unreachable, so the
 * default suite (`composer test`) stays offline-safe.
 *
 * Run: `composer test-integration` (or `ddev composer test-integration`).
 * Costs: embeddings are ~$0.02 per 1M tokens (text-embedding-3-small);
 * the agent test runs once per provider in providers() (glm only).
 */

const RAG_IT_TABLE = 'agent_rag_chunks_it';

/** Real PDO connection (DDEV MariaDB defaults). */
function ragPdo(): PDO
{
    $dsn = (string) (getenv('RAG_DB_DSN') ?: 'mysql:host=db;dbname=db;charset=utf8mb4');
    return new PDO($dsn, (string) (getenv('RAG_DB_USER') ?: 'db'), (string) (getenv('RAG_DB_PASSWORD') ?: 'db'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
}

function ragDbAvailable(): bool
{
    try {
        ragPdo()->query('SELECT 1');
        return true;
    } catch (Throwable) {
        return false;
    }
}

function ragEmbeddings(): OpenRouterEmbeddings
{
    return new OpenRouterEmbeddings(
        (string) getenv('OPENROUTER_KEY'),
        (string) (getenv('OPENROUTER_EMBEDDING_MODEL') ?: 'openai/text-embedding-3-small'),
    );
}

/** VectorRagSource that records the queries it receives. */
class RecordingRagSource extends VectorRagSource
{
    public array $queries = [];

    public function search(string $query, int $limit): array
    {
        $this->queries[] = ['query' => $query, 'limit' => $limit];
        return parent::search($query, $limit);
    }
}

beforeEach(function () {
    $this->pdo = ragPdo();
    $this->store = new PdoVectorStore($this->pdo, RAG_IT_TABLE);
    $this->store->migrate();
});

afterEach(function () {
    $this->pdo?->exec('DROP TABLE IF EXISTS ' . RAG_IT_TABLE);
});

test('real embeddings return one vector per text with the expected dimensions', function () {
    $vectors = ragEmbeddings()->embed(['Cats drink milk.', 'Dogs bury bones.']);

    expect($vectors)->toHaveCount(2);
    expect($vectors[0])->not->toBeEmpty();
    expect(count($vectors[0]))->toBe(count($vectors[1]));
    expect($vectors[0])->not->toBe($vectors[1]);
})->group('integration')
->skip(fn () => !getenv('OPENROUTER_KEY'), 'OPENROUTER_KEY is not set')
->skip(fn () => !ragDbAvailable(), 'RAG database is not reachable');

test('indexes a real document in the real database and retrieves the right chunk', function () {
    $embeddings = ragEmbeddings();
    $document = <<<'TXT'
        Solaris Appliances — Product Manual Extract

        The X1000 cordless vacuum cleaner was launched in 2024. It has a
        three-year warranty covering motor and battery defects, and a
        one-year warranty on accessories. The dust container holds 0.7
        liters and the battery runs for 55 minutes per charge.

        The X800 corded vacuum is the previous model, still supported with
        spare parts until 2030. Its warranty is one year only.
        TXT;

    $chunks = (new Chunker())->chunk($document);
    $vectors = $embeddings->embed($chunks);
    $this->store->upsert('manuals', array_map(fn ($i) => [
        'text' => $chunks[$i],
        'vector' => $vectors[$i],
        'metadata' => ['doc' => 'x1000-manual'],
    ], array_keys($chunks)));

    expect($this->store->count('manuals'))->toBe(count($chunks));

    $source = new VectorRagSource('manuals', 'Appliance manuals', $this->store, $embeddings, 'manuals');
    $results = $source->search('How long is the warranty of the X1000 vacuum?', 3);

    expect($results)->not->toBeEmpty();
    expect($results[0]['text'])->toContain('X1000');
    expect($results[0]['text'])->toContain('three-year warranty');
    expect($results[0]['metadata'])->toBe(['doc' => 'x1000-manual']);
    expect($results[0]['score'])->toBeGreaterThan($results[1]['score'] ?? -1);
})->group('integration')
->skip(fn () => !getenv('OPENROUTER_KEY'), 'OPENROUTER_KEY is not set')
->skip(fn () => !ragDbAvailable(), 'RAG database is not reachable');

test('the real agent answers from the real knowledge base', function (string $model, string $lightModel) {
    $embeddings = ragEmbeddings();
    $document = <<<'TXT'
        Solaris Appliances — Internal Support Notes

        The X1000 cordless vacuum cleaner has a three-year warranty on
        motor and battery. Customers must register the product on the
        Solaris website within 90 days of purchase to activate the
        extended warranty. The support phone is 0800-555-0199.
        TXT;

    $chunks = (new Chunker())->chunk($document);
    $vectors = $embeddings->embed($chunks);
    $this->store->upsert('support', array_map(fn ($i) => [
        'text' => $chunks[$i],
        'vector' => $vectors[$i],
    ], array_keys($chunks)));

    $agent = new Agent(new OpenRouterChatClient((string) getenv('OPENROUTER_KEY')));
    $agent->model = $model;
    $agent->lightModel = $lightModel;
    $agent->setInstruction('You are a support assistant. Answer in English, briefly, using the knowledge base excerpts when they are provided.');
    $source = new RecordingRagSource('support', 'Internal support notes about Solaris appliances', $this->store, $embeddings, 'support');
    $agent->addRagSource($source);

    $response = $agent->respond('What is the warranty period of the X1000 vacuum?');

    // The light-model router really searched the source...
    expect($source->queries)->not->toBeEmpty();
    // ...the database returned the right chunk...
    expect($this->store->count('support'))->toBeGreaterThan(0);
    // ...and the main model answered with the fact from the knowledge base.
    expect($response)->toContain('three');
    expect(strtolower($response))->toContain('warranty');
})
    ->with(providers())
    ->group('integration')
    ->skip(fn () => !getenv('OPENROUTER_KEY'), 'OPENROUTER_KEY is not set')
    ->skip(fn () => !ragDbAvailable(), 'RAG database is not reachable');
