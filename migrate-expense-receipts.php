<?php
/** One-time migration: adds receipt columns to the expenses table. Safe to run more than once. */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';

$pdo = db();

$has = $pdo->prepare(
    "SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'expenses' AND column_name = 'receipt_path'"
);
$has->execute();

if ((int)$has->fetchColumn() > 0) {
    echo "Already up to date — expenses.receipt_path exists.\n";
    exit;
}

$pdo->exec(
    'ALTER TABLE expenses
       ADD COLUMN receipt_path VARCHAR(255) NULL AFTER expense_date,
       ADD COLUMN receipt_name VARCHAR(150) NULL AFTER receipt_path,
       ADD COLUMN receipt_mime VARCHAR(100) NULL AFTER receipt_name'
);

echo "Done — added receipt_path, receipt_name and receipt_mime to expenses.\n";
