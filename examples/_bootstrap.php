<?php

/**
 * Shared bootstrap for the examples: loads the project .env (real
 * environment wins) and provides requireEnv(), which prints a helpful
 * message and exits when a variable is missing.
 *
 * Every example starts with:  require __DIR__ . '/_bootstrap.php';
 */

require __DIR__ . '/../vendor/autoload.php';

(function (): void {
    $file = __DIR__ . '/../.env';
    if (is_readable($file)) {
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
            [$key, $value] = explode('=', $line, 2);
            if (trim($key) !== '' && getenv(trim($key)) === false) putenv(trim($key) . '=' . trim($value));
        }
    }
})();

/** Returns an environment variable, exiting with a message when missing. */
function requireEnv(string $key): string
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        fwrite(STDERR, "Set {$key} in .env first (see .env.example).\n");
        exit(1);
    }
    return (string) $value;
}

/** Interactive chat loop shared by the examples. */
function chatLoop(AgentIa\Agent $agent, string $hint): void
{
    echo "\nChat ready. {$hint} Ctrl+C to exit.\n";
    while (true) {
        echo "\nyou> ";
        $line = fgets(STDIN);
        if ($line === false) break; // EOF (piped input or Ctrl+D)
        $message = trim($line);
        if ($message === '') continue;
        echo "agent> " . $agent->respond($message) . "\n";
    }
}
