<?php
/**
 * One-time migration: stores how much cash the customer handed over (walk-in cash orders),
 * so the receipt can show Cash and Change. Run once: php migrate-cash-payment.php
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';

$pdo = db();
$column = $pdo->query("SHOW COLUMNS FROM orders LIKE 'cash_received'")->fetch(PDO::FETCH_ASSOC);

if ($column) {
    echo "Already up to date - orders.cash_received exists.\n";
    exit;
}

$pdo->exec('ALTER TABLE orders ADD COLUMN cash_received DECIMAL(10,2) NULL DEFAULT NULL');
echo "Done - added orders.cash_received.\n";
