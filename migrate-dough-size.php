<?php
/**
 * Pizza dough per size.
 *
 * Adds ingredients.dough_size (inches: 14 / 16 / 20 ...). A stock with a dough size is deducted
 * automatically: every pizza sold uses 1 dough of the size the customer picked, whatever the flavor.
 *
 * Run once:  php migrate-dough-size.php      (safe to run again)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';

$pdo = db();

if (!$pdo->query("SHOW COLUMNS FROM ingredients LIKE 'dough_size'")->fetch(PDO::FETCH_ASSOC)) {
    $pdo->exec("ALTER TABLE ingredients ADD COLUMN dough_size SMALLINT NULL AFTER per_drink_qty");
    echo "ingredients.dough_size added.\n";
} else {
    echo "ingredients.dough_size already exists.\n";
}
// A dough stock is deducted by size, so it must not also sit in a recipe (double deduction).
$pdo->exec("DELETE FROM product_recipes WHERE ingredient_id IN (SELECT id FROM ingredients WHERE dough_size IS NOT NULL)");
echo "Done.\n";
