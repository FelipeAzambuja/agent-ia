<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use AgentIa\Agent;
use AgentIa\Rag\Rag;

/**
 * Simplest possible RAG example: no database at all. The Rag facade
 * bundles chunker, embeddings and an in-memory store — you just add()
 * documents and hand it to the agent. Only the embeddings and the chat
 * use the OpenRouter API.
 *
 * Run (with DDEV, from the project root):
 *   ddev exec php examples/rag-simple.php
 *
 * Requires OPENROUTER_KEY in .env (see .env.example).
 *
 * To move to a database later, pass a persistent store — the rest of
 * the code stays the same:
 *   $rag = new Rag('manuals', '...', $key, new PdoVectorStore($pdo));
 */

$openrouterKey = requireEnv('OPENROUTER_KEY');

// --- The knowledge base: chunker + embeddings + in-memory store, all in one --

$rag = new Rag(
    'manuals',
    'Solaris appliance manuals: warranties, specs, usage and troubleshooting of the X1000 and X800 vacuums',
    $openrouterKey, // in-memory store by default; pass a PdoVectorStore to persist
);

// --- Indexing: one call per document -----------------------------------------

$rag->add('Solaris X1000 Cordless Vacuum. Launched in 2024. Three-year warranty on motor and battery, one year on accessories. Dust container: 0.7 liters. Battery: 55 minutes per charge. Loss of suction is usually a full container or a clogged filter; wash the filter and let it dry for 24 hours.', ['doc' => 'x1000']);
$rag->add('Solaris X800 Corded Vacuum. Previous corded model, spare parts until 2030. Warranty: one year only. Bag capacity: 3.5 liters. No battery; the power cable is 6 meters long. If the motor does not start, check the thermal fuse: unplug for 30 minutes and try again.', ['doc' => 'x800']);
echo "[ok] manuals indexed ({$rag->count()} chunks)\n";

// --- The agent ---------------------------------------------------------------

$agent = new Agent($openrouterKey);
$agent->setInstruction('You are the support assistant of Solaris Appliances. Answer briefly, in English, using the knowledge base excerpts when they are provided.');
$agent->addRag($rag);

chatLoop($agent, "Ask about the manuals (e.g. 'What is the warranty of the X1000?').");
