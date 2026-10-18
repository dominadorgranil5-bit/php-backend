<?php
/**
 * POST /api/auth/ping.php — web heartbeat.
 * The web app calls this every 10s while a customer/staff/admin is signed in. Authenticating the
 * request (current_user via require_user) refreshes sessions.last_seen and cancels a pending "bye",
 * so the session is only ended when the browser really goes away (see lib/auth.php).
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_method('POST');

require_user();
json_out(['ok' => true]);
