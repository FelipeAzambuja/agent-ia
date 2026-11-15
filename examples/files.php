<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use AgentIa\Agent;

/**
 * Multimodal files: images, PDFs and text documents (local path or
 * URL) can be attached to a turn. Files apply only to the current
 * turn — they are not stored in the history.
 *
 * Run (with DDEV, from the project root):
 *   ddev exec php examples/files.php /path/to/chart.png
 *   ddev exec php examples/files.php https://example.com/contract.pdf
 *
 * Requires OPENROUTER_KEY in .env (see .env.example).
 */

$path = $argv[1] ?? null;
if ($path === null || !str_starts_with($path, 'http') && !is_readable($path)) {
    fwrite(STDERR, "Usage: ddev exec php examples/files.php <image-or-pdf-path-or-url>\n");
    exit(1);
}

$agent = new Agent(requireEnv('OPENROUTER_KEY'));
$agent->setInstruction('You are a document analyst. Be precise and brief, in English.');

echo "agent> " . $agent->respond('Analyze this file and summarize the main points.', [$path]) . "\n";
