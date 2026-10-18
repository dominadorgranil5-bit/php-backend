<?php
/** lib/response.php — small helpers for consistent JSON responses. */

function json_out($data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error(string $message, int $status = 400): never
{
    json_out(['error' => $message], $status);
}

/** Reads and JSON-decodes the request body; [] if empty/invalid. */
function json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function require_method(string $method): void
{
    if ($_SERVER['REQUEST_METHOD'] !== $method) {
        json_error('Method not allowed', 405);
    }
}

/** Pulls a required string field out of an array, trims it, 400s if missing/empty. */
function require_field(array $data, string $key): string
{
    $value = trim((string)($data[$key] ?? ''));
    if ($value === '') {
        json_error("Missing required field: $key", 400);
    }
    return $value;
}
