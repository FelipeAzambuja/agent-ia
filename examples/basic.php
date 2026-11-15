<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use AgentIa\Agent;
use AgentIa\OpenRouterChatClient;

/**
 * The smallest possible agent: one instruction, one turn. No memory,
 * no tools, no RAG — just a chat model with a persona.
 *
 * Run (with DDEV, from the project root):
 *   ddev exec php examples/basic.php
 *   ddev exec php examples/basic.php "How do I reset my password?"
 *
 * Requires OPENROUTER_KEY in .env (see .env.example).
 */

$agent = applyEnvModels(new Agent(new OpenRouterChatClient(requireEnv('OPENROUTER_KEY'))));
$agent->setInstruction('You are a friendly support assistant. Answer briefly, in English.');

// One-shot: message comes from the command line.
if (isset($argv[1])) {
    echo "agent> " . $agent->respond($argv[1]) . "\n";
    exit(0);
}

// Otherwise: interactive chat.
chatLoop($agent, "Ask anything (e.g. 'How do I reset my password?').");
