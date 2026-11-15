<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use AgentIa\Agent;
use AgentIa\OpenRouterChatClient;

/**
 * Orchestration: an agent with subagents ("agent as tool"). The
 * orchestrator routes the subject by each subagent's description and
 * delegates with a structured handoff (summary, handled, not_handled,
 * expected). A light model assesses whether the subagent met the
 * expectation; on failure the orchestrator tries the next candidate
 * and, if none succeeds, answers by itself.
 *
 * Subagents are stateless on purpose: they receive the full transcript
 * plus the handoff on every invocation.
 *
 * Run (with DDEV, from the project root):
 *   ddev exec php examples/subagents.php
 *
 * Requires OPENROUTER_KEY in .env (see .env.example).
 */

$client = new OpenRouterChatClient(requireEnv('OPENROUTER_KEY'));

// The subagents: each one only handles its own subject.
$visits = applyEnvModels((new Agent($client))
    ->setName('Clovis')
    ->setInstruction('You only handle visit scheduling. Confirm date and time, in English, briefly.'));

$financing = applyEnvModels((new Agent($client))
    ->setName('Bruna')
    ->setInstruction('You only handle financing. Talk about installments, rates and credit, in English, briefly.'));

// The orchestrator: routes by description and delegates.
$orchestrator = applyEnvModels(new Agent($client));
$orchestrator->setInstruction('You are the main attendant of a home appliance store. For anything about visits or financing, ALWAYS delegate to the matching specialist — never answer those subjects yourself. Simple greetings and small talk you answer directly. In English.');

$orchestrator->addSubAgent($visits, 'Handles calls about scheduling a visit to the store or a technical visit');
$orchestrator->addSubAgent($financing, 'Handles calls about financing, installments and credit');

chatLoop($orchestrator, "Try 'I want to schedule a visit.' or 'Can I pay a fridge in 12 installments?'.");
