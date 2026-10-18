<?php
/**
 * POST /api/auth/bye.php?token=... — sent with navigator.sendBeacon when the tab/window closes.
 * Starts the short grace window; a page refresh (any request within the window) cancels it,
 * otherwise the session ends. Beacons can't set headers, so the token comes in the query string
 * (bearer_token() accepts it). Always answers ok — the browser ignores the response.
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_method('POST');

mark_session_closing();
json_out(['ok' => true]);
