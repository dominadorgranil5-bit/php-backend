<?php
/**
 * Pizza box per size.
 *
 * Adds ingredients.box_size (inches: 14 / 16 / 20 ...). A stock with a box size is deducted
 * automatically: every pizza sold uses 1 box of the size the customer picked, whatever the flavor
 * (same idea as ingredients.dough_size).
 *
 * Run once:  php migrate-box-size.php      (safe to run again)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';

$pdo = db();

if (!$pdo->query("SHOW COLUMNS FROM ingredients LIKE 'box_size'")->fetch(PDO::FETCH_ASSOC)) {
    $pdo->exec("ALTER TABLE ingredients ADD COLUMN box_size SMALLINT NULL AFTER dough_size");
    echo "ingredients.box_size added.\n";
} else {
    echo "ingredients.box_size already exists.\n";
}
// A box stock is deducted by size, so it must not also sit in a recipe (double deduction).
$pdo->exec("DELETE FROM product_recipes WHERE ingredient_id IN (SELECT id FROM ingredients WHERE box_size IS NOT NULL)");
echo "Done.\n";
