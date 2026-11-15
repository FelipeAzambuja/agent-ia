<?php

use AgentIa\Agent;
use AgentIa\OpenRouterChatClient;

/**
 * Integration tests against the real OpenRouter API. They issue real
 * requests and are skipped when OPENROUTER_KEY is not set, so the
 * default suite (`composer test`) stays offline-safe.
 *
 * Enable them: cp .env.example .env, set your key, then
 * `composer test-integration` (or `ddev composer test-integration`).
 * Each test runs once per provider in providers() (gpt, deepseek and
 * glm), so a full run costs a few cents.
 */

/**
 * Provider matrix: main model + light model per provider. The light
 * model does the internal structured decisions and must support
 * structured outputs.
 *
 * Claude (anthropic/claude-sonnet-4.6) is intentionally absent: with
 * `tool_choice: auto` and the subagent handoff schema (4 required
 * string fields), it consistently declines to call the subagent tool
 * and answers the user itself, which fails the delegation test. The
 * behavior was isolated against the raw API (schema without required
 * fields or `tool_choice: required` both make it delegate), so it is
 * model judgment, not a library bug. Re-add it if the Agent design
 * changes (optional handoff fields or forced tool choice).
 *
 * Qwen (qwen3.7-max) and Mistral (mistral-large-4-0) are also absent:
 * Qwen was inconsistent in the raw delegation probe (refused 1 of 2
 * calls) and Mistral took ~49s per delegation. All providers below
 * passed the full suite consistently.
 *
 * Cost/performance of the matrix (OpenRouter prices, USD per 1M
 * tokens in/out; delegation test timing on 2026-11-15):
 *   - glm:      cheapest ($0.08/$3.60, light $0.04/$0.08), fastest (~4-6s)
 *   - minimax:  cheap ($0.15/$0.60), fast (~5s)
 *   - deepseek: cheap ($0.15/$0.60), moderate (~6-11s)
 *   - gpt:      pricier main ($2/$10, light $0.10/$0.50), moderate (~13s)
 *   - grok:     pricier ($2/$6), moderate (~9-13s)
 *   - kimi:     cheap input, expensive output ($0.22/$8.41), moderate (~13s)
 *   - gemini:   mid ($0.83/$4.12), slowest of the set (~16s)
 */
function providers(): array
{
    return [
        // 'gpt' => ['openai/gpt-6.1-sol', 'openai/gpt-6-luna'],
        // 'deepseek' => ['deepseek/deepseek-v4.1-flash-1017', 'deepseek/deepseek-v4.1-flash-1017'],
        'glm' => ['z-ai/glm-5.3', 'z-ai/glm-5.3-flash'],
        // 'gemini' => ['google/gemini-3.8-flash', 'google/gemini-3.8-flash'],
        // 'grok' => ['x-ai/grok-4.7', 'x-ai/grok-4.7'],
        // 'kimi' => ['moonshotai/kimi-k3.1-pro', 'moonshotai/kimi-k3.1-pro'],
        // 'minimax' => ['minimax/minimax-m3.1-flash', 'minimax/minimax-m3.1-flash'],
    ];
}

/** Agent wired to the real API with a given provider's models. */
function openrouterAgent(string $model, string $lightModel): Agent
{
    $agent = new Agent(new OpenRouterChatClient((string) getenv('OPENROUTER_KEY')));
    $agent->model = $model;
    $agent->lightModel = $lightModel;
    return $agent;
}

/** Subagent that records the transcript + handoff it receives. */
class RecordingSubAgent extends Agent
{
    public array $received = [];

    public function respond(string $message, array $files = []): string
    {
        $this->received[] = $message;
        return parent::respond($message, $files);
    }
}

test('answers a real turn and keeps it in the history', function (string $model, string $lightModel) {
    $agent = openrouterAgent($model, $lightModel);
    $agent->setInstruction('You are an echo bot. Always reply with the single word PONG and nothing else.');

    $response = $agent->respond('ping');

    expect($response)->not->toBe('');
    $history = new ReflectionProperty(Agent::class, 'history');
    $history->setAccessible(true);
    expect($history->getValue($agent))->toBe([
        ['role' => 'user', 'content' => 'ping'],
        ['role' => 'assistant', 'content' => $response],
    ]);
})
    ->with(providers())
    ->group('integration')
    ->skip(fn () => !getenv('OPENROUTER_KEY'), 'OPENROUTER_KEY is not set — copy .env.example to .env and add your key');

test('answers using a real tool call', function (string $model, string $lightModel) {
    $agent = openrouterAgent($model, $lightModel);
    $agent->setInstruction('You answer using the available tools. When the user asks for the secret code, you MUST call the tool and report exactly what it returns.');
    $calls = 0;
    $agent->setTools([
        'secret_code' => [
            'description' => 'Returns the current secret code. Use it for any question about the secret code.',
            'parameters' => ['type' => 'object', 'properties' => []],
            'execute' => function () use (&$calls) {
                $calls++;

                return 'XJ4-992';
            },
        ],
    ]);

    $response = $agent->respond('What is the current secret code?');

    expect($calls)->toBeGreaterThanOrEqual(1);
    expect($response)->toContain('XJ4-992');
})
    ->with(providers())
    ->group('integration')
    ->skip(fn () => !getenv('OPENROUTER_KEY'), 'OPENROUTER_KEY is not set — copy .env.example to .env and add your key');

test('delegates to a real subagent with the handoff', function (string $model, string $lightModel) {
    $orchestrator = openrouterAgent($model, $lightModel);
    $orchestrator->setInstruction('You are an orchestrator. You never handle domain requests yourself: always delegate them to the available subagent tool, then relay its answer to the user.');

    $sub = new RecordingSubAgent(new OpenRouterChatClient((string) getenv('OPENROUTER_KEY')));
    $sub->model = $model;
    $sub->lightModel = $lightModel;
    $sub->setName('Clovis')->setInstruction('You are the visit-scheduling specialist of a real-estate agency. When asked to schedule a visit, confirm it briefly with a date.');
    $orchestrator->addSubAgent($sub, 'Schedules apartment visits');

    $response = $orchestrator->respond('I would like to schedule a visit to the apartment.');

    // The subagent was invoked with the transcript + the structured handoff...
    expect($sub->received)->not->toBeEmpty();
    expect($sub->received[0])->toContain('Handoff from the orchestrator agent:');
    // ...and the orchestrator answered the user.
    expect($response)->not->toBe('');
})
    ->with(providers())
    ->group('integration')
    ->skip(fn () => !getenv('OPENROUTER_KEY'), 'OPENROUTER_KEY is not set — copy .env.example to .env and add your key');
