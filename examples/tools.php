<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use AgentIa\Agent;
use AgentIa\OpenRouterChatClient;

/**
 * Function calling: the model decides when to call your PHP functions.
 * Two tools here — one takes no arguments, one takes a required string.
 * The agent runs the tool loop by itself (up to 10 rounds); a tool
 * error goes back to the model as a tool result, so it can retry with
 * another approach in the same turn.
 *
 * Run (with DDEV, from the project root):
 *   ddev exec php examples/tools.php
 *
 * Requires OPENROUTER_KEY in .env (see .env.example).
 */

// Your real business logic lives in plain functions.
function fetchOrderStatus(string $number): string
{
    // Fake database: in production this would query your system.
    return match (strtoupper($number)) {
        'A-1001' => 'Shipped — arrives Thursday',
        'A-1002' => 'Processing — payment confirmed, not shipped yet',
        default  => "Order {$number} not found",
    };
}

$agent = applyEnvModels(new Agent(new OpenRouterChatClient(requireEnv('OPENROUTER_KEY'))));
$agent->setInstruction('You are an order support assistant. Answer briefly, in English, using the tools when they help.');

$agent->setTools([
    'current_time' => [
        'description' => 'Returns the current server date and time',
        'parameters' => ['type' => 'object', 'properties' => []],
        'execute' => fn () => date('d/m/Y H:i'),
    ],
    'order_status' => [
        'description' => 'Returns the status of an order by number (try A-1001 or A-1002)',
        'parameters' => [
            'type' => 'object',
            'properties' => ['number' => ['type' => 'string']],
            'required' => ['number'],
        ],
        'execute' => fn (array $args) => fetchOrderStatus((string) $args['number']),
    ],
]);

chatLoop($agent, "Try 'What time is it?' or 'Where is my order A-1001?'.");
