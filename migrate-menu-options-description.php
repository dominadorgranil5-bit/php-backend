<?php
/**
 * migrate-menu-options-description.php — one-time migration for databases
 * that were set up before `menu_options.description` existed.
 *
 * Safe to run more than once (checks first). If you're setting up a brand
 * new database, you don't need this — schema.sql already includes the
 * column.
 *
 * Usage: php migrate-menu-options-description.php
 *   (or open it once in the browser, then delete/rename it)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';

$pdo = db();
$columns = $pdo->query('SHOW COLUMNS FROM menu_options')->fetchAll(PDO::FETCH_COLUMN);

if (in_array('description', $columns, true)) {
    echo "Already up to date — menu_options.description exists.\n";
} else {
    $pdo->exec('ALTER TABLE menu_options ADD COLUMN description VARCHAR(255) NULL AFTER name');
    echo "Done — added menu_options.description.\n";
}
