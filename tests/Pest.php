<?php

/*
 * Pest configuration: the tests are pure functions over real objects,
 * with no TestCase and no extra bootstrap (vendor/autoload.php covers it).
 */

/*
 * Loads the project .env into the environment, without requiring
 * vlucas/phpdotenv: KEY=VALUE lines, # comments and optional quotes.
 * Variables already present in the real environment always win.
 * Powers the OpenRouter integration tests (tests/OpenRouterTest.php).
 */
(function (): void {
    $file = __DIR__ . '/../.env';
    if (!is_readable($file)) {
        return;
    }
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($value !== '' && in_array($value[0], ['"', "'"], true) && str_ends_with($value, $value[0])) {
            $value = substr($value, 1, -1);
        }
        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . $value);
        }
    }
})();
