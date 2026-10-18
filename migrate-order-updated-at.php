<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';

$pdo = db();
$column = $pdo->query("SHOW COLUMNS FROM orders LIKE 'updated_at'")->fetch(PDO::FETCH_ASSOC);

if ($column) {
    echo "Already up to date — orders.updated_at exists.\n";
    exit;
}

$pdo->exec(
    'ALTER TABLE orders ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
);
echo "Done — added orders.updated_at.\n";