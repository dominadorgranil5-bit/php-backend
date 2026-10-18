<?php
/** Add dine-in as a supported order type for existing databases. */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';

$pdo = db();
$column = $pdo->query("SHOW COLUMNS FROM orders LIKE 'order_type'")->fetch(PDO::FETCH_ASSOC);
$definition = "ENUM('pickup','delivery','dinein') NOT NULL DEFAULT 'delivery'";

if ($column && str_contains($column['Type'], "'dinein'")) {
    echo "Already up to date — orders.order_type supports dinein.\n";
    exit;
}

if ($column) {
    $pdo->exec("ALTER TABLE orders MODIFY COLUMN order_type $definition");
    echo "Done — added dinein to orders.order_type.\n";
} else {
    $pdo->exec("ALTER TABLE orders ADD COLUMN order_type $definition AFTER status");
    echo "Done — added orders.order_type with dinein support.\n";
}