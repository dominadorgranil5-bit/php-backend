<?php
/**
 * One-time migration: auto log-out when a web browser/tab is closed (admin, staff, riders).
 *  - sessions.last_seen: the web app pings the server every few seconds; if the pings stop
 *    (browser closed, laptop asleep, crash) the session counts as ended. NULL = no heartbeat
 *    enforcement (native app sessions, customers).
 * Safe to run more than once.   Run:  php migrate-session-heartbeat.php   (or open it once in the browser)
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';

$pdo = db();
$exists = (int)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sessions' AND COLUMN_NAME = 'last_seen'"
)->fetchColumn() > 0;
if (!$exists) {
    $pdo->exec('ALTER TABLE sessions ADD COLUMN last_seen DATETIME NULL');
}
$closing = (int)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sessions' AND COLUMN_NAME = 'closing_at'"
)->fetchColumn() > 0;
if (!$closing) {
    // Set by api/auth/bye.php when the tab/window closes; cleared by the next request (page refresh).
    $pdo->exec('ALTER TABLE sessions ADD COLUMN closing_at DATETIME NULL');
}
echo "Done - session heartbeat is ready.\n";
