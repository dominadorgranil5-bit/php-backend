<?php
/**
 * Recipe-based inventory.
 *
 * Creates:
 *   ingredients           - stock per ingredient (always stored in its base unit: ml / g / pc)
 *   product_recipes       - how much of each ingredient one unit of a menu item uses
 *   inventory_movements   - audit log (sale / restock / adjustment)
 *   orders.inventory_deducted_at - set once an order's stock was deducted (prevents double deduction)
 *
 * Also seeds the coffee-series ingredients (stock = 0) and, for menu items whose name matches,
 * the recipes from the handwritten sheet. Frappe recipes are NOT seeded (no measurements yet).
 *
 * Run once:  php migrate-inventory.php      (safe to run again)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';

$pdo = db();

$pdo->exec("CREATE TABLE IF NOT EXISTS ingredients (
  id               CHAR(36)      NOT NULL PRIMARY KEY,
  name             VARCHAR(120)  NOT NULL,
  unit             ENUM('ml','g','pc') NOT NULL DEFAULT 'ml',
  stock            DECIMAL(12,2) NOT NULL DEFAULT 0,
  low_stock_level  DECIMAL(12,2) NOT NULL DEFAULT 0,
  pump_ml          DECIMAL(6,2)  NULL,
  per_drink_qty    DECIMAL(6,2)  NOT NULL DEFAULT 0,
  created_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ingredients_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$perDrinkAdded = false;
if (!$pdo->query("SHOW COLUMNS FROM ingredients LIKE 'per_drink_qty'")->fetch(PDO::FETCH_ASSOC)) {
    // Amount auto-deducted for EVERY drink sold (cups, lids, straws...) - no recipe line needed.
    $pdo->exec("ALTER TABLE ingredients ADD COLUMN per_drink_qty DECIMAL(6,2) NOT NULL DEFAULT 0 AFTER pump_ml");
    $perDrinkAdded = true;
    echo "ingredients.per_drink_qty added.\n";
}

$pdo->exec("CREATE TABLE IF NOT EXISTS product_recipes (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  menu_item_id   CHAR(36)      NOT NULL,
  ingredient_id  CHAR(36)      NOT NULL,
  quantity       DECIMAL(10,2) NOT NULL,
  UNIQUE KEY uq_recipe_line (menu_item_id, ingredient_id),
  KEY idx_recipe_ingredient (ingredient_id),
  CONSTRAINT fk_recipe_item FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_recipe_ingredient FOREIGN KEY (ingredient_id) REFERENCES ingredients(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS inventory_movements (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  ingredient_id  CHAR(36)      NOT NULL,
  order_id       CHAR(36)      NULL,
  change_qty     DECIMAL(12,2) NOT NULL,
  reason         ENUM('sale','restock','adjustment') NOT NULL,
  note           VARCHAR(255)  NULL,
  created_by     CHAR(36)      NULL,
  created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_mov_ingredient (ingredient_id, created_at),
  KEY idx_mov_order (order_id),
  CONSTRAINT fk_mov_ingredient FOREIGN KEY (ingredient_id) REFERENCES ingredients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$col = $pdo->query("SHOW COLUMNS FROM orders LIKE 'inventory_deducted_at'")->fetch(PDO::FETCH_ASSOC);
if (!$col) {
    $pdo->exec("ALTER TABLE orders ADD COLUMN inventory_deducted_at DATETIME NULL");
    // Orders that were already finished before inventory existed must never be deducted retroactively.
    $pdo->exec("UPDATE orders SET inventory_deducted_at = NOW(), updated_at = updated_at
                WHERE status IN ('completed','delivered')");
    echo "orders.inventory_deducted_at added.\n";
}

// ---- Seed ingredients (name => [unit, pump_ml, low_stock_level, per_drink_qty]) --------------------------
$ingredients = [
    'Cold Brew'            => ['ml', null, 2000, 0],
    'Milk'                 => ['ml', null, 2000, 0],
    'Condensed Milk'       => ['ml', null, 500, 0],
    'Chocolate Syrup'      => ['ml', 10,   200, 0],
    'Hazelnut Syrup'       => ['ml', 10,   200, 0],
    'Salted Caramel Syrup' => ['ml', 10,   200, 0],
    'French Vanilla Syrup' => ['ml', 10,   200, 0],
    'Caramel Syrup'        => ['ml', 10,   200, 0],
    'Creamy Vanilla'       => ['ml', 10,   200, 0],
    'Cup'                  => ['pc', null, 50, 1],
    'Lid'                  => ['pc', null, 50, 1],
];
$ids = [];
$find = $pdo->prepare('SELECT id FROM ingredients WHERE name = :n');
$ins  = $pdo->prepare('INSERT INTO ingredients (id, name, unit, pump_ml, low_stock_level, per_drink_qty) VALUES (:id,:n,:u,:p,:l,:d)');
foreach ($ingredients as $name => [$unit, $pump, $low, $perDrink]) {
    $find->execute(['n' => $name]);
    $id = $find->fetchColumn();
    if (!$id) {
        $id = make_uuid();
        $ins->execute(['id' => $id, 'n' => $name, 'u' => $unit, 'p' => $pump, 'l' => $low, 'd' => $perDrink]);
        echo "Ingredient added: $name\n";
    }
    $ids[$name] = $id;
}

// Cup & Lid from an earlier run of this script: switch them to automatic per-drink deduction.
if ($perDrinkAdded) {
    $pdo->exec("UPDATE ingredients SET per_drink_qty = 1 WHERE name IN ('Cup','Lid')");
}
// Anything deducted per drink must not ALSO sit in a recipe (it would be deducted twice).
$pdo->exec("DELETE FROM product_recipes WHERE ingredient_id IN (SELECT id FROM ingredients WHERE per_drink_qty > 0)");

// ---- Seed recipes from the handwritten sheet (qty in ml; 1 pump = 10 ml) ------------------
// Coffee Mocha condensed milk = 7 ml (confirmed from the handwritten sheet).
$recipes = [
    'coffee mocha'    => ['Cold Brew' => 120, 'Chocolate Syrup' => 20, 'Condensed Milk' => 7, 'Milk' => 100],
    'hazelnut'        => ['Cold Brew' => 120, 'Hazelnut Syrup' => 20, 'Milk' => 100],
    'salted caramel'  => ['Cold Brew' => 120, 'Salted Caramel Syrup' => 20, 'Milk' => 100],
    'french vanilla'  => ['Cold Brew' => 120, 'French Vanilla Syrup' => 20, 'Milk' => 100],
    'spanish latte'   => ['Cold Brew' => 100, 'Condensed Milk' => 20, 'Caramel Syrup' => 20, 'Milk' => 100],
    'iced americano'  => ['Cold Brew' => 200, 'Creamy Vanilla' => 20],
];
$norm = fn(string $s) => preg_replace('/[^a-z]/', '', strtolower($s));
$menu = $pdo->query('SELECT id, name, category FROM menu_items')->fetchAll();
$lineIns = $pdo->prepare('INSERT IGNORE INTO product_recipes (menu_item_id, ingredient_id, quantity) VALUES (:m,:i,:q)');
$unmatched = array_keys($recipes);
foreach ($menu as $m) {
    $key = $norm($m['name']);
    // Frappe items are not part of the coffee-series sheet yet.
    if (str_contains($key, 'frappe')) continue;
    foreach ($recipes as $recipeName => $lines) {
        $rk = $norm($recipeName);
        // exact match, or "Spanish Latte - Premium" style names that start with the recipe name
        if ($key === $rk || str_starts_with($key, $rk)) {
            foreach ($lines as $ing => $qty) {
                $lineIns->execute(['m' => $m['id'], 'i' => $ids[$ing], 'q' => $qty]);
            }
            echo "Recipe seeded: {$m['name']}\n";
            $unmatched = array_values(array_diff($unmatched, [$recipeName]));
            break;
        }
    }
}
foreach ($unmatched as $u) {
    echo "No menu item matched \"$u\" - add its recipe in Admin > Inventory > Recipes.\n";
}
echo "Done.\n";
