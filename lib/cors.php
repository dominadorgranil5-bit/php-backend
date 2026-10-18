<?php
/** lib/cors.php — CORS headers, matches the permissive setup the Supabase edge functions used. */

function apply_cors(): void
{
    $allowed = config('APP_CORS_ORIGINS', '*');
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

    if ($allowed === '*') {
        header('Access-Control-Allow-Origin: *');
    } else {
        $list = array_map('trim', explode(',', $allowed));
        if (in_array($origin, $list, true)) {
            header("Access-Control-Allow-Origin: $origin");
            header('Vary: Origin');
        }
    }

    header('Access-Control-Allow-Headers: authorization, content-type, x-web-session');
    header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');

    $isLocalhostOrigin = preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#i', $origin) === 1;
    $requestsPrivateNetwork = strtolower($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_PRIVATE_NETWORK'] ?? '') === 'true';
    if ($isLocalhostOrigin && $requestsPrivateNetwork) {
        header('Access-Control-Allow-Private-Network: true');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}
