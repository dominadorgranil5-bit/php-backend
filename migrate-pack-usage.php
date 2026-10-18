<?php
/**
 * Packs: "when this stock is used up, take 1 pack out of another stock".
 *
 * Example: Cold Brew (ml). 1 pack of Coffee makes 25 L, so on Cold Brew set
 *   pack_ingredient_id = Coffee Pack,  pack_size = 25000 (ml per pack).
 * Every time 25,000 ml of Cold Brew has been used by sales, 1 Coffee Pack is deducted automatically.
 *
 * Adds to ingredients:
 *   pack_ingredient_id  the stock that is deducted per pack (e.g. Coffee Pack)
 *   pack_size           how much of THIS stock one pack makes (in this stock's unit)
 *   pack_used           running counter of how much was used since the last pack was deducted
 *
 * Run once:  php migrate-pack-usage.php      (safe to run again)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';

$pdo = db();

$has = fn(string $col) => (bool)$pdo->query("SHOW COLUMNS FROM ingredients LIKE '$col'")->fetch(PDO::FETCH_ASSOC);

if (!$has('pack_ingredient_id')) {
    $pdo->exec("ALTER TABLE ingredients ADD COLUMN pack_ingredient_id CHAR(36) NULL");
    echo "ingredients.pack_ingredient_id added.\n";
}
if (!$has('pack_size')) {
    $pdo->exec("ALTER TABLE ingredients ADD COLUMN pack_size DECIMAL(12,2) NULL");
    echo "ingredients.pack_size added.\n";
}
if (!$has('pack_used')) {
    $pdo->exec("ALTER TABLE ingredients ADD COLUMN pack_used DECIMAL(12,2) NOT NULL DEFAULT 0");
    echo "ingredients.pack_used added.\n";
}
try {
    $pdo->exec("ALTER TABLE ingredients ADD CONSTRAINT fk_ingredient_pack FOREIGN KEY (pack_ingredient_id) REFERENCES ingredients(id) ON DELETE SET NULL");
    echo "Foreign key added.\n";
} catch (Throwable $e) {
    echo "Foreign key already exists (or could not be added) - fine.\n";
}
echo "Done.\n";
