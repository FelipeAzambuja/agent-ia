<?php

use AgentIa\Agent;
use AgentIa\Rag\ArrayVectorStore;
use AgentIa\Rag\Chunker;
use AgentIa\Rag\PdoVectorStore;
use AgentIa\Rag\Rag;
use AgentIa\Rag\VectorRagSource;

/**
 * Offline RAG tests: routing, context injection, the search tool and
 * the storage/chunking components, all with fakes — no network.
 */

test('addRagSource registers the search tool with the expected schema', function () {
    $agent = new Agent(new FakeChatClient());
    $agent->addRagSource(new FakeRagSource('Refund Policy!', 'Refund rules', []));

    $tools = (new ReflectionProperty(Agent::class, 'tools'));
    $tools->setAccessible(true);
    $tools = $tools->getValue($agent);

    expect(array_keys($tools))->toBe(['search_refund_policy']);
    expect($tools['search_refund_policy']['description'])->toContain('Refund rules');
    expect($tools['search_refund_policy']['parameters'])->toBe([
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => [
            'query' => ['type' => 'string'],
            'limit' => ['type' => 'integer'],
            'reason' => ['type' => 'string'],
        ],
        'required' => ['query', 'reason'],
    ]);
});

test('pre-turn router injects the retrieved chunks as a system block', function () {
    $source = new FakeRagSource('policy', 'Refund rules', [
        ['text' => 'Refunds within 30 days.', 'score' => 0.92, 'metadata' => []],
    ]);
    $client = new FakeChatClient(
        [['content' => 'The refund deadline is 30 days.']],
        [['needed' => true, 'sources' => ['policy'], 'query' => 'refund deadline', 'reason' => 'asks about refunds']],
    );
    $agent = new Agent($client);
    $agent->addRagSource($source);

    $response = $agent->respond('What is the refund deadline?');

    expect($response)->toBe('The refund deadline is 30 days.');
    $messages = $client->chatCalls[0]['messages'];
    expect($messages)->toHaveCount(2);
    expect($messages[0]['role'])->toBe('system');
    expect($messages[0]['content'])->toContain('Relevant excerpts from the knowledge bases');
    expect($messages[0]['content'])->toContain('reason: asks about refunds');
    expect($messages[0]['content'])->toContain('## policy');
    expect($messages[0]['content'])->toContain('[score 0.92] Refunds within 30 days.');
    expect($messages[1])->toBe(['role' => 'user', 'content' => 'What is the refund deadline?']);
    // The router searched the source with the rewritten query.
    expect($source->queries)->toBe([['query' => 'refund deadline', 'limit' => 5]]);
});

test('router decides no retrieval is needed and the turn proceeds without RAG', function () {
    $source = new FakeRagSource('policy', 'Refund rules');
    $client = new FakeChatClient(
        [['content' => 'Hello!']],
        [['needed' => false, 'sources' => [], 'query' => '', 'reason' => 'greeting']],
    );
    $agent = new Agent($client);
    $agent->addRagSource($source);

    expect($agent->respond('Hi!'))->toBe('Hello!');
    expect($client->chatCalls[0]['messages'])->toBe([['role' => 'user', 'content' => 'Hi!']]);
    expect($source->queries)->toBe([]);
});

test('routing failure does not block the response', function () {
    $client = new class([['content' => 'Hello!']]) extends FakeChatClient {
        public function structuredChat(array $messages, array $schema, string $model, array $options = []): array
        {
            throw new RuntimeException('router down');
        }
    };
    $source = new FakeRagSource('policy', 'Refund rules', [['text' => 'x', 'score' => 1.0, 'metadata' => []]]);
    $agent = new Agent($client);
    $agent->addRagSource($source);

    expect($agent->respond('Hi!'))->toBe('Hello!');
    expect($client->chatCalls[0]['messages'])->toBe([['role' => 'user', 'content' => 'Hi!']]);
    expect($source->queries)->toBe([]);
});

test('unknown source names returned by the router are ignored', function () {
    $source = new FakeRagSource('policy', 'Refund rules', [['text' => 'x', 'score' => 1.0, 'metadata' => []]]);
    $client = new FakeChatClient(
        [['content' => 'Answer.']],
        [['needed' => true, 'sources' => ['nonexistent'], 'query' => 'q', 'reason' => 'r']],
    );
    $agent = new Agent($client);
    $agent->addRagSource($source);

    $agent->respond('Hi!');
    expect($client->chatCalls[0]['messages'])->toBe([['role' => 'user', 'content' => 'Hi!']]);
    expect($source->queries)->toBe([]);
});

test('the main model can search a RAG source mid-turn as a tool', function () {
    $source = new FakeRagSource('policy', 'Refund rules', [
        ['text' => 'Refunds within 30 days.', 'score' => 0.91, 'metadata' => []],
        ['text' => 'Refunds need the receipt.', 'score' => 0.88, 'metadata' => []],
    ]);
    // The pre-turn router consumes one structured call (empty queue → not needed).
    $client = new FakeChatClient([
        ['content' => null, 'tool_calls' => [['id' => 'c1', 'function' => ['name' => 'search_policy', 'arguments' => json_encode([
            'query' => 'refund deadline', 'limit' => 2, 'reason' => 'user asked about refunds',
        ])]]]],
        ['content' => 'The deadline is 30 days.'],
    ]);
    $agent = new Agent($client);
    $agent->addRagSource($source);

    expect($agent->respond('What is the refund deadline?'))->toBe('The deadline is 30 days.');
    expect($source->queries)->toBe([['query' => 'refund deadline', 'limit' => 2]]);
    $tools = array_values(array_filter($client->chatCalls[1]['messages'], fn ($m) => $m['role'] === 'tool'));
    expect($tools)->toHaveCount(1);
    expect($tools[0]['content'])->toContain("Excerpts from policy:");
    expect($tools[0]['content'])->toContain('[score 0.91] Refunds within 30 days.');
    expect($tools[0]['content'])->toContain('[score 0.88] Refunds need the receipt.');
});

test('the search tool reports when nothing relevant is found', function () {
    $source = new FakeRagSource('policy', 'Refund rules', []);
    $client = new FakeChatClient([
        ['content' => null, 'tool_calls' => [['id' => 'c1', 'function' => ['name' => 'search_policy', 'arguments' => '{"query":"x","reason":"y"}']]]],
        ['content' => 'Nothing found, answered from memory.'],
    ]);
    $agent = new Agent($client);
    $agent->addRagSource($source);

    expect($agent->respond('Hi'))->toBe('Nothing found, answered from memory.');
    $tools = array_values(array_filter($client->chatCalls[1]['messages'], fn ($m) => $m['role'] === 'tool'));
    expect($tools[0]['content'])->toBe('No relevant excerpts found in policy for this query.');
    // Without limit, the source default (5) applies.
    expect($source->queries)->toBe([['query' => 'x', 'limit' => 5]]);
});

test('VectorRagSource embeds the query as search_query and returns the nearest chunks', function () {
    $embeddings = new FakeEmbeddings();
    $store = new FakeVectorStore();
    $store->upsert('kb', [
        ['text' => 'Cats drink milk.', 'vector' => $embeddings->embed(['Cats drink milk.'])[0]],
        ['text' => 'Dogs bury bones.', 'vector' => $embeddings->embed(['Dogs bury bones.'])[0]],
    ]);
    $source = new VectorRagSource('pets', 'Pet facts', $store, $embeddings, 'kb');

    $results = $source->search('cats and milk', 3);

    expect(end($embeddings->calls)['type'])->toBe('search_query');
    expect($results)->toHaveCount(2);
    expect($results[0]['text'])->toBe('Cats drink milk.');
    expect($results[0]['score'])->toBeGreaterThan($results[1]['score']);
});

test('Rag facade indexes with add() and searches without exposing the internals', function () {
    $embeddings = new FakeEmbeddings();
    $store = new FakeVectorStore();
    $rag = new Rag('manuals', 'Appliance manuals', $embeddings, $store);

    $chunks = $rag->add('The X1000 has a three-year warranty on motor and battery.');
    expect($chunks)->toBe(1);
    expect($rag->count())->toBe(1);
    // Documents are indexed immediately (no save step) as search_document.
    expect($embeddings->calls[0]['type'])->toBe('search_document');

    $rag->add('The X800 has a one-year warranty.', ['doc' => 'x800']);
    expect($rag->count())->toBe(2);

    $results = $rag->search('x1000 warranty motor battery', 1);
    expect($results)->toHaveCount(1);
    expect($results[0]['text'])->toContain('three-year warranty');
    expect(end($embeddings->calls)['type'])->toBe('search_query');
    // Metadata travels with the chunks.
    expect($store->namespaces['manuals'][1]['metadata'])->toBe(['doc' => 'x800']);
});

test('Rag facade accepts an API key and defaults to an in-memory store', function () {
    $rag = new Rag('faq', 'Frequently asked questions', 'sk-test-key');

    $ref = new ReflectionProperty($rag, 'embeddings');
    $ref->setAccessible(true);
    expect($ref->getValue($rag))->toBeInstanceOf(AgentIa\Rag\OpenRouterEmbeddings::class);

    $ref = new ReflectionProperty($rag, 'store');
    $ref->setAccessible(true);
    expect($ref->getValue($rag))->toBeInstanceOf(ArrayVectorStore::class);

    $ref = new ReflectionProperty($rag, 'namespace');
    $ref->setAccessible(true);
    expect($ref->getValue($rag))->toBe('faq');
});

test('Rag facade is a RagSource and addRag registers the search tool', function () {
    $rag = new Rag('manuals', 'Appliance manuals', new FakeEmbeddings());
    expect($rag)->toBeInstanceOf(AgentIa\Rag\RagSource::class);

    $agent = new Agent(new FakeChatClient());
    $agent->addRag($rag);

    $tools = (new ReflectionProperty(Agent::class, 'tools'));
    $tools->setAccessible(true);
    expect(array_keys($tools->getValue($agent)))->toBe(['search_manuals']);
});

test('addFile indexes a file with the filename as metadata', function () {
    $file = tempnam(sys_get_temp_dir(), 'rag') . '.txt';
    file_put_contents($file, 'Refunds are processed within five business days.');
    try {
        $rag = new Rag('policies', 'Refund rules', new FakeEmbeddings(), new FakeVectorStore());
        expect($rag->addFile($file))->toBe(1);

        $ref = new ReflectionProperty($rag, 'store');
        $ref->setAccessible(true);
        $store = $ref->getValue($rag);
        expect($store->namespaces['policies'][0]['metadata'])->toBe(['doc' => basename($file)]);
    } finally {
        unlink($file);
    }
});

test('ArrayVectorStore indexes in memory and ranks by cosine similarity', function () {
    $store = new ArrayVectorStore();
    $store->upsert('ns', [
        ['text' => 'about cats', 'vector' => [1.0, 0.0, 0.0], 'metadata' => ['page' => 1]],
        ['text' => 'about dogs', 'vector' => [0.0, 1.0, 0.0]],
    ]);
    $store->upsert('ns', [['text' => 'more cats', 'vector' => [0.9, 0.1, 0.0]]]);
    $store->upsert('other', [['text' => 'elsewhere', 'vector' => [1.0, 0.0, 0.0]]]);

    expect($store->count('ns'))->toBe(3);
    expect($store->count('other'))->toBe(1);
    expect($store->count('missing'))->toBe(0);

    $results = $store->similar('ns', [1.0, 0.1, 0.0], 2);
    expect($results)->toHaveCount(2);
    expect($results[0]['text'])->toBe('more cats');
    expect($results[1]['text'])->toBe('about cats');
    expect($results[1]['metadata'])->toBe(['page' => 1]);
    expect($results[0]['score'])->toBeGreaterThan($results[1]['score']);

    // Namespaces are isolated and the limit is respected.
    expect($store->similar('other', [1.0, 0.0, 0.0], 5))->toHaveCount(1);
    expect($store->similar('other', [1.0, 0.0, 0.0], 5)[0]['text'])->toBe('elsewhere');
});

test('PdoVectorStore migrates, indexes and ranks by cosine similarity (SQLite)', function () {
    $store = new PdoVectorStore(new PDO('sqlite::memory:'));
    $store->migrate();
    $store->upsert('ns', [
        ['text' => 'about cats', 'vector' => [1.0, 0.0, 0.0], 'metadata' => ['page' => 1]],
        ['text' => 'about dogs', 'vector' => [0.0, 1.0, 0.0]],
    ]);
    $store->upsert('other', [['text' => 'elsewhere', 'vector' => [1.0, 0.0, 0.0]]]);

    expect($store->count('ns'))->toBe(2);
    expect($store->count('other'))->toBe(1);

    $results = $store->similar('ns', [1.0, 0.1, 0.0], 1);
    expect($results)->toHaveCount(1);
    expect($results[0]['text'])->toBe('about cats');
    expect($results[0]['metadata'])->toBe(['page' => 1]);
    expect($results[0]['score'])->toBeGreaterThan(0.9);
});

test('Chunker keeps short texts whole and splits long ones within the limit', function () {
    $chunker = new Chunker(size: 100, overlap: 20);

    expect($chunker->chunk(''))->toBe([]);
    expect($chunker->chunk('Short text.'))->toBe(['Short text.']);

    $paragraph = 'The first sentence explains the refund policy in detail. The second sentence adds the deadline of thirty days. The third sentence mentions the required receipt. The fourth sentence covers digital products. The fifth sentence closes the topic.';
    $chunks = $chunker->chunk($paragraph);

    expect(count($chunks))->toBeGreaterThan(1);
    foreach ($chunks as $chunk) {
        expect(mb_strlen($chunk))->toBeLessThanOrEqual(100);
    }
    // Overlap: the second chunk starts with the tail of the first.
    expect($chunks[1])->toContain('thirty days');
});

test('Chunker hard-cuts a single sentence longer than the limit', function () {
    $chunker = new Chunker(size: 50, overlap: 10);
    $long = 'Word ' . str_repeat('word ', 30);
    $chunks = $chunker->chunk(trim($long));

    expect(count($chunks))->toBeGreaterThan(1);
    foreach ($chunks as $chunk) {
        expect(mb_strlen($chunk))->toBeLessThanOrEqual(50);
    }
});
