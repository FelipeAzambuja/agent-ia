<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_manuals.php';

/**
 * Indexing side of the support RAG: reads the manuals and stores their
 * chunks + embeddings in the database. This is the part that runs
 * OUTSIDE the attendance flow — once per document, or on a cron/webhook
 * when the docs change. The chat side (rag-support.php) only reads what
 * is stored here.
 *
 * Run (with DDEV, from the project root):
 *   ddev exec php examples/rag-index.php
 *
 * Requires OPENROUTER_KEY in .env (see .env.example) and the database
 * of _manuals.php (DDEV MariaDB by default).
 *
 * Note: upsert() appends — running this twice duplicates the chunks.
 * In production, delete the rows of a document before reindexing it.
 */

$rag = supportRag();
$rag->migrate(); // creates the agent_rag_chunks table if missing

if ($rag->count() > 0) {
    echo "[skip] manuals already indexed ({$rag->count()} chunks). Delete the rows in agent_rag_chunks to reindex.\n";
    exit(0);
}

foreach (supportManuals() as $doc => $text) {
    $chunks = $rag->add($text, ['doc' => $doc, 'version' => '2024-11']);
    echo "[ok] {$doc} indexed ({$chunks} chunks)\n";
}

echo "[done] {$rag->count()} chunks in total. Now run: ddev exec php examples/rag-support.php\n";
