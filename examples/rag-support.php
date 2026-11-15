<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_manuals.php';

use AgentIa\Agent;
use AgentIa\OpenRouterChatClient;

/**
 * Attendance side of the support RAG: an agent that answers from the
 * manuals indexed by rag-index.php. This script never indexes — it
 * only reads the knowledge base from the database, which is how the
 * "indexing once, querying many" split looks in practice.
 *
 * Run (with DDEV, from the project root):
 *   ddev exec php examples/rag-index.php   (once, or on a cron)
 *   ddev exec php examples/rag-support.php
 *
 * Requires OPENROUTER_KEY in .env (see .env.example) and the database
 * of _manuals.php (DDEV MariaDB by default).
 *
 * What happens per turn, under the hood: a light model decides whether
 * the message needs the manuals and rewrites the query; the retrieved
 * chunks are injected as a system block; the main model answers from
 * them — and can also call the search_manuals tool itself mid-turn.
 */

$rag = supportRag();

if ($rag->count() === 0) {
    fwrite(STDERR, "The knowledge base is empty. Index it first:\n  ddev exec php examples/rag-index.php\n");
    exit(1);
}
echo "[ok] knowledge base ready ({$rag->count()} chunks)\n";

$agent = applyEnvModels(new Agent(new OpenRouterChatClient(requireEnv('OPENROUTER_KEY'))));
$agent->setInstruction('You are the support assistant of Solaris Appliances. Answer briefly, in English, using the knowledge base excerpts when they are provided.');
$agent->addRag($rag);

chatLoop($agent, "Ask about the manuals (e.g. 'What is the warranty of the X1000?').");
