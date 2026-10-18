<?php
/**
 * Adds orders.paymongo_payment_intent_id — the PayMongo Payment Intent id
 * behind a QR Ph dynamic QR code (see api/payments/create-qrph-intent.php),
 * alongside the existing paymongo_source_id column used by the direct
 * GCash-app redirect flow (create-gcash-source.php). webhook.php looks
 * orders up by whichever column is set.
 *
 * Run once: php migrate-gcash-qrph.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';

$pdo = db();
$column = $pdo->query("SHOW COLUMNS FROM orders LIKE 'paymongo_payment_intent_id'")->fetch(PDO::FETCH_ASSOC);

if ($column) {
    echo "Already up to date — orders.paymongo_payment_intent_id already exists.\n";
    exit;
}

$pdo->exec(
    "ALTER TABLE orders
     ADD COLUMN paymongo_payment_intent_id VARCHAR(190) NULL AFTER paymongo_source_id,
     ADD KEY idx_orders_intent (paymongo_payment_intent_id)"
);
echo "Done — orders.paymongo_payment_intent_id added.\n";
