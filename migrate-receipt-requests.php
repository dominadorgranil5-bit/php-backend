<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';

$pdo = db();
$columns = $pdo->query("SHOW COLUMNS FROM orders LIKE 'receipt_requested_at'")->fetch(PDO::FETCH_ASSOC);

if (!$columns) {
    $pdo->exec('ALTER TABLE orders ADD COLUMN receipt_requested_at DATETIME NULL AFTER payment_status');
}

$columns = $pdo->query("SHOW COLUMNS FROM orders LIKE 'receipt_printed_at'")->fetch(PDO::FETCH_ASSOC);
if (!$columns) {
    $pdo->exec('ALTER TABLE orders ADD COLUMN receipt_printed_at DATETIME NULL AFTER receipt_requested_at');
}

echo "Receipt request columns are up to date.\n";