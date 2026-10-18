<?php
/** One-time migration for existing databases that predate expense tracking. */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';

$pdo = db();
$exists = $pdo->prepare(
    "SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = 'expenses'"
);
$exists->execute();

if ((int)$exists->fetchColumn() > 0) {
    echo "Already up to date — expenses table exists.\n";
    exit;
}

$pdo->exec(
    'CREATE TABLE expenses (
      id           CHAR(36)      NOT NULL PRIMARY KEY,
      recorded_by  CHAR(36)      NOT NULL,
      category     VARCHAR(80)   NOT NULL,
      description  VARCHAR(255)  NULL,
      amount       DECIMAL(10,2) NOT NULL,
      expense_date DATE          NOT NULL,
      created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_expenses_date (expense_date),
      KEY idx_expenses_recorded_by (recorded_by),
      CONSTRAINT fk_expenses_recorded_by FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

echo "Done — created expenses table.\n";
