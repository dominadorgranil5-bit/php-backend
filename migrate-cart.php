<?php
/** One-time migration: adds the customer_carts table (each account's saved cart, shared across devices). */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';

$pdo = db();
$exists = $pdo->prepare(
    "SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = 'customer_carts'"
);
$exists->execute();

if ((int)$exists->fetchColumn() > 0) {
    echo "Already up to date — customer_carts table exists.\n";
    exit;
}

$pdo->exec(
    "CREATE TABLE customer_carts (
      user_id    CHAR(36)   NOT NULL PRIMARY KEY,
      items      MEDIUMTEXT NOT NULL,
      updated_at DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      CONSTRAINT fk_cart_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

echo "Done — created customer_carts table.\n";
