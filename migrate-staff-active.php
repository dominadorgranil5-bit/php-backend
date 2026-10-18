<?php
/** One-time migration for existing databases that predate the staff enable/disable toggle. */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';

$pdo = db();
$exists = $pdo->prepare(
    "SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'is_active'"
);
$exists->execute();

if ((int)$exists->fetchColumn() > 0) {
    echo "Already up to date — users.is_active exists.\n";
    exit;
}

$pdo->exec(
    "ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER role"
);

echo "Done — added users.is_active (defaults everyone to enabled).\n";
