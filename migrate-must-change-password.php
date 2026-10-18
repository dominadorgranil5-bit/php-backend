<?php
/**
 * One-time migration: adds users.must_change_password.
 * Accounts the admin creates for staff/riders (and temporary passwords the admin resets for them)
 * get must_change_password = 1, so the person is forced to choose their own password on first sign-in.
 * Existing accounts are NOT affected (they default to 0).
 *
 * Run once:  http://localhost/php-backend/migrate-must-change-password.php   (or: php migrate-must-change-password.php)
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';

$pdo = db();
$exists = $pdo->prepare(
    "SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'must_change_password'"
);
$exists->execute();

if ((int)$exists->fetchColumn() > 0) {
    echo "Already up to date — users.must_change_password exists.\n";
    exit;
}

$pdo->exec("ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active");
echo "Done — added users.must_change_password (existing accounts default to 0).\n";
