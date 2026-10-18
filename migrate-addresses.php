<?php
/** One-time migration: adds the customer_addresses table ("My Addresses", max 5 per customer). */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';

$pdo = db();
$exists = $pdo->prepare(
    "SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = 'customer_addresses'"
);
$exists->execute();

if ((int)$exists->fetchColumn() > 0) {
    echo "Already up to date — customer_addresses table exists.\n";
    exit;
}

$pdo->exec(
    "CREATE TABLE customer_addresses (
      id          CHAR(36)     NOT NULL PRIMARY KEY,
      user_id     CHAR(36)     NOT NULL,
      label       VARCHAR(40)  NOT NULL DEFAULT '',
      street      VARCHAR(200) NOT NULL DEFAULT '',
      barangay    VARCHAR(100) NOT NULL,
      city        VARCHAR(100) NOT NULL,
      province    VARCHAR(100) NOT NULL,
      postal_code VARCHAR(10)  NOT NULL,
      is_default  TINYINT(1)   NOT NULL DEFAULT 0,
      created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_addr_user (user_id),
      CONSTRAINT fk_addr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

echo "Done — created customer_addresses table.\n";
