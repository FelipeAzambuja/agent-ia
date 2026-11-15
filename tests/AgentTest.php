<?php

use AgentIa\Agent;

/**
 * Offline Agent tests: all behavior is exercised with the FakeChatClient
 * (scripted responses), no network and no API key.
 */

test('answers a simple turn and keeps it in the history', function () {
    $client = new FakeChatClient([['content' => 'Hello! How can I help?']]);
    $agent = new Agent($client);

    $response = $agent->respond('Hi!');

    expect($response)->toBe('Hello! How can I help?');
    expect($client->chatCalls)->toHaveCount(1);
    expect($client->chatCalls[0]['messages'])->toBe([
        ['role' => 'user', 'content' => 'Hi!'],
    ]);
    $history = (new ReflectionProperty(Agent::class, 'history'));
    $history->setAccessible(true);
    expect($history->getValue($agent))->toBe([
        ['role' => 'user', 'content' => 'Hi!'],
        ['role' => 'assistant', 'content' => 'Hello! How can I help?'],
    ]);
});

test('runs the tool and feeds the error back to the model when it fails', function () {
    $client = new FakeChatClient([
        ['content' => null, 'tool_calls' => [['id' => 'c1', 'function' => ['name' => 'current_time', 'arguments' => '{}']]]],
        ['content' => null, 'tool_calls' => [['id' => 'c2', 'function' => ['name' => 'broken', 'arguments' => '{"x":1}']]]],
        ['content' => 'Done, solved without the tool.'],
    ]);
    $agent = new Agent($client);
    $agent->setTools([
        'current_time' => [
            'description' => 'Current server date and time',
            'parameters' => ['type' => 'object', 'properties' => []],
            'execute' => fn () => '14:00',
        ],
        'broken' => [
            'description' => 'Always fails',
            'parameters' => ['type' => 'object', 'properties' => ['x' => ['type' => 'integer']]],
            'execute' => fn () => throw new RuntimeException('database down'),
        ],
    ]);

    $response = $agent->respond('What time is it?');

    expect($response)->toBe('Done, solved without the tool.');
    // The tool result (success and error) reaches the model as a 'tool' message.
    $messages = $client->chatCalls[2]['messages'];
    $tools = array_values(array_filter($messages, fn ($m) => $m['role'] === 'tool'));
    expect($tools)->toHaveCount(2);
    expect($tools[0]['content'])->toBe('14:00');
    expect($tools[1]['content'])->toContain('Error executing tool broken');
    expect($tools[1]['content'])->toContain('database down');
});

test('memory survives across instances with an injected backend', function () {
    $backend = new FakeMemoryBackend();
    $a = new Agent(new FakeChatClient([['content' => 'Nice to meet you, Felipe!']]));
    $a->sessionId = 'sess-1';
    $a->memory = $backend;
    $a->respond('My name is Felipe.');

    $b = new Agent(new FakeChatClient([['content' => 'Your name is Felipe.']]));
    $b->sessionId = 'sess-1';
    $b->memory = $backend;
    $b->respond('What is my name?');

    // The second instance loads the history from the backend before responding.
    expect($backend->sessions['sess-1'])->toHaveCount(4);
    expect($backend->sessions['sess-1'][0])->toBe(['role' => 'user', 'content' => 'My name is Felipe.']);
});

test('sessionId is required when a memory backend is set', function () {
    $agent = new Agent(new FakeChatClient());
    $agent->memory = new FakeMemoryBackend();
    $agent->respond('Hi');
})->throws(LogicException::class, 'sessionId is required');

test('addSubAgent registers the tool with a normalized name and delegates with the handoff', function () {
    $sub = new FakeSubAgent('subagent response');
    $sub->setName('Clovis Handles Visits');

    $client = new FakeChatClient([], [['fulfilled' => true]]);
    $agent = new Agent($client);
    $agent->addSubAgent($sub, 'Handles calls about visits');
    $agent->addSubAgent(new Agent(new FakeChatClient()), 'Handles calls about financing');

    $tools = (new ReflectionProperty(Agent::class, 'tools'));
    $tools->setAccessible(true);
    $tools = $tools->getValue($agent);

    // A defined name becomes a normalized tool; without a name, subagent_N.
    expect(array_keys($tools))->toBe(['clovis_handles_visits', 'subagent_2']);
    expect($tools['clovis_handles_visits']['parameters'])->toBe([
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => [
            'summary' => ['type' => 'string'],
            'handled' => ['type' => 'string'],
            'not_handled' => ['type' => 'string'],
            'expected' => ['type' => 'string'],
        ],
        'required' => ['summary', 'handled', 'not_handled', 'expected'],
    ]);

    // The tool delegates to the subagent with transcript + handoff, and the status comes back in the result.
    $output = ($tools['clovis_handles_visits']['execute'])([
        'summary' => 'Client wants to visit an apartment',
        'handled' => 'Greeted and confirmed the interest',
        'not_handled' => 'No access to the visit schedule',
        'expected' => 'Schedule the visit and confirm the date',
    ]);
    expect($output)->toContain('subagent response');
    expect($output)->toContain('[Delegation status: the subagent did handle what was expected.]');
    expect($sub->received)->toHaveCount(1);
    expect($sub->received[0])->toContain('Conversation so far:');
    expect($sub->received[0])->toContain("Handoff from the orchestrator agent:\nSummary: Client wants to visit an apartment\n"
        . "What it already handled: Greeted and confirmed the interest\n"
        . "What it could not handle: No access to the visit schedule\n"
        . 'What it expects the subagent to handle: Schedule the visit and confirm the date');
});

test('failure status guides the orchestrator to try the next subagent', function () {
    $sub = new FakeSubAgent('I could not handle this request.');
    $client = new FakeChatClient([], [['fulfilled' => false]]);
    $agent = new Agent($client);
    $agent->addSubAgent($sub, 'Handles visits');

    $tools = (new ReflectionProperty(Agent::class, 'tools'));
    $tools->setAccessible(true);
    $tools = $tools->getValue($agent);
    $output = ($tools['subagent_1']['execute'])([
        'summary' => 'x', 'handled' => '', 'not_handled' => '', 'expected' => 'Schedule a visit',
    ]);

    expect($output)->toContain('[Delegation status: the subagent did NOT handle what was expected.');
    expect($output)->toContain('If another possible subagent exists, delegate to it; if none can, answer the user yourself.');
});

test('assessment failure assumes the subagent succeeded', function () {
    $sub = new FakeSubAgent('response');
    // structuredChat with an empty queue returns [] → the assessor treats it as fulfilled.
    $client = new FakeChatClient([], []);
    $agent = new Agent($client);
    $agent->addSubAgent($sub, 'Handles visits');

    $tools = (new ReflectionProperty(Agent::class, 'tools'));
    $tools->setAccessible(true);
    $tools = $tools->getValue($agent);
    $output = ($tools['subagent_1']['execute'])([
        'summary' => 'x', 'handled' => '', 'not_handled' => '', 'expected' => 'y',
    ]);

    expect($output)->toContain('the subagent did handle');
});

test('tool loop routes to the next subagent and uses its response', function () {
    $refuser = new FakeSubAgent('Unfortunately I could not handle this request.');
    $refuser->setName('first');
    $resolver = new FakeSubAgent('VISIT SCHEDULED SUCCESSFULLY. Team secret code: PINEAPPLE-42.');
    $resolver->setName('second');

    $client = new FakeChatClient(
        [
            // Round 1: orchestrator delegates to the first subagent.
            ['content' => null, 'tool_calls' => [['id' => 'c1', 'function' => ['name' => 'first', 'arguments' => json_encode([
                'summary' => 'Client wants to schedule a visit',
                'handled' => 'Nothing yet',
                'not_handled' => 'No access to the schedule',
                'expected' => 'Schedule the visit',
            ])]]]],
            // Round 2: failure status, orchestrator delegates to the second.
            ['content' => null, 'tool_calls' => [['id' => 'c2', 'function' => ['name' => 'second', 'arguments' => json_encode([
                'summary' => 'Client wants to schedule a visit; first subagent failed',
                'handled' => 'Nothing',
                'not_handled' => 'First subagent could not handle it',
                'expected' => 'Schedule the visit',
            ])]]]],
            // Round 3: final response passing the success through.
            ['content' => 'VISIT SCHEDULED SUCCESSFULLY. Team secret code: PINEAPPLE-42.'],
        ],
        // Status assessments: first failure, then success.
        [['fulfilled' => false], ['fulfilled' => true]],
    );
    $agent = new Agent($client);
    $agent->addSubAgent($refuser, 'Handles visits (first attempt)');
    $agent->addSubAgent($resolver, 'Handles visits (when the first one fails)');

    $response = $agent->respond('I want to schedule a visit to the apartment.');

    expect($response)->toContain('PINEAPPLE-42');
    expect($refuser->received)->toHaveCount(1);
    expect($refuser->received[0])->toContain('I want to schedule a visit to the apartment.');
    expect($refuser->received[0])->toContain('What it expects the subagent to handle: Schedule the visit');
    expect($resolver->received)->toHaveCount(1);
    expect($resolver->received[0])->toContain('first subagent failed');
});

test('tool names with accents and symbols are normalized', function () {
    $client = new FakeChatClient();
    $agent = new Agent($client);
    $sub = new FakeSubAgent();
    $sub->setName('  Attention!! Café & Visit  ');
    $agent->addSubAgent($sub, 'x');

    $tools = (new ReflectionProperty(Agent::class, 'tools'));
    $tools->setAccessible(true);
    expect(array_keys($tools->getValue($agent)))->toBe(['attention_cafe_visit']);
});
