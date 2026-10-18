<?php
/**
 * Adds 'payment_failed' to orders.status.
 *
 * Used for GCash orders whose PayMongo payment came back unsuccessful
 * (declined, expired, or the customer backed out of checkout). Orders in
 * this state are deliberately excluded from api/orders/all.php and
 * api/orders/stream.php's staff/admin fingerprint, so they never reach the
 * order staff / admin screens — see lib/payment_notifications.php.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';

$pdo = db();
$column = $pdo->query("SHOW COLUMNS FROM orders LIKE 'status'")->fetch(PDO::FETCH_ASSOC);

if ($column && str_contains($column['Type'], 'payment_failed')) {
    echo "Already up to date — orders.status already has 'payment_failed'.\n";
    exit;
}

$pdo->exec(
    "ALTER TABLE orders MODIFY COLUMN status
     ENUM('pending','preparing','ready','completed','cancelled','payment_failed')
     NOT NULL DEFAULT 'pending'"
);
echo "Done — orders.status now accepts 'payment_failed'.\n";
