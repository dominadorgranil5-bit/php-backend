<?php
/** One-time migration for existing databases that predate void numbers. */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';

$pdo = db();
$exists = $pdo->prepare(
    "SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = 'void_numbers'"
);
$exists->execute();

if ((int)$exists->fetchColumn() > 0) {
    echo "Already up to date — void_numbers table exists.\n";
    exit;
}

$pdo->exec(
    'CREATE TABLE void_numbers (
      id          CHAR(36)     NOT NULL PRIMARY KEY,
      code        VARCHAR(50)  NOT NULL,
      created_by  CHAR(36)     NULL,
      created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_void_numbers_code (code),
      KEY idx_void_numbers_created_by (created_by),
      CONSTRAINT fk_void_numbers_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

echo "Done — created void_numbers table.\n";
