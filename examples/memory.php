<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use AgentIa\Agent;
use AgentIa\Memory\PdoMemoryBackend;
use AgentIa\Memory\PdoTopicsBackend;

/**
 * Persistent memory: without a backend the history lives only in the
 * process. Here both backends point to a SQLite file, so the
 * conversation survives between runs — run the example twice and
 * notice the agent remembers the previous chat.
 *
 *   - memory:      full conversational history per session
 *   - topicMemory: long-term topics (a light model creates/updates
 *                  topics per subject; another picks the relevant
 *                  ones per turn)
 *
 * Run (with DDEV, from the project root):
 *   ddev exec php examples/memory.php
 *
 * Requires OPENROUTER_KEY in .env (see .env.example).
 */

$dbFile = __DIR__ . '/memory.sqlite';
$pdo = new PDO('sqlite:' . $dbFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// The backends expect these tables (see the docblocks of each class);
// there is no migrate() for memory, so the example creates them itself.
$pdo->exec('CREATE TABLE IF NOT EXISTS agent_memories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id TEXT NOT NULL,
    role TEXT NOT NULL,
    content TEXT NOT NULL,
    created_at TEXT NOT NULL
)');
$pdo->exec('CREATE TABLE IF NOT EXISTS agent_topics (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id TEXT NOT NULL,
    description TEXT NOT NULL,
    messages TEXT NOT NULL,
    notes TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
)');

$agent = new Agent(requireEnv('OPENROUTER_KEY'));
$agent->setInstruction('You are a personal assistant. Answer briefly, in English, and remember what the user tells you.');
$agent->sessionId   = 'demo'; // fixed on purpose: re-running continues the same conversation
$agent->memory      = new PdoMemoryBackend($pdo);
$agent->topicMemory = new PdoTopicsBackend($pdo);

chatLoop($agent, "Try 'My name is Felipe and I hate cilantro.' — then run the example again and ask if it remembers.");
