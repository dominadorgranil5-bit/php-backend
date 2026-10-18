<?php
/**
 * config.php — loads settings from .env (same folder) into env vars.
 * Include this first in every entry point (bootstrap.php does it for you).
 */

function env_load(string $path): void
{
    if (!is_file($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
        $key = trim($key);
        $value = trim($value);
        // strip optional surrounding quotes
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
            $value = substr($value, 1, -1);
        }
        // Always overwrite from the .env file on every request. Apache worker
        // processes are reused across requests, and putenv() sets a
        // process-wide value that persists between them — so a "set only if
        // not already set" guard here would make edits to .env invisible
        // until every worker happens to recycle. Re-reading the file each
        // time keeps it in sync immediately after a save.
        if ($key !== '') {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

env_load(__DIR__ . '/.env');

function config(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    return $value === false ? $default : $value;
}
