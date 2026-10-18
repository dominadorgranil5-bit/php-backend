<?php
/**
 * Explains WHY menu items show OUT OF ORDER because of stock.
 *
 * Run:  php diagnose-out-of-stock.php            (all items that are currently out of stock)
 *       php diagnose-out-of-stock.php "hungarian"  (only items whose name contains the text)
 *
 * Read-only: it changes nothing.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';

$pdo = db();
$filter = strtolower(trim((string)($argv[1] ?? '')));

$fmt = fn($n) => rtrim(rtrim(number_format((float)$n, 2, '.', ''), '0'), '.');
$foodCategories = ['pizza', 'burger', 'pasta', 'chicken wings', 'wings', 'waffles', 'waffle'];

$items = $pdo->query('SELECT id, name, category, available FROM menu_items ORDER BY category, name')->fetchAll();
$recipeStmt = $pdo->prepare(
    'SELECT i.name, i.unit, i.stock, i.low_stock_level, i.per_drink_qty, i.dough_size, i.box_size, r.quantity
     FROM product_recipes r JOIN ingredients i ON i.id = r.ingredient_id
     WHERE r.menu_item_id = :id ORDER BY i.name'
);
$perDrink = $pdo->query('SELECT name, unit, stock, per_drink_qty FROM ingredients WHERE per_drink_qty > 0')->fetchAll();

$shown = 0;
foreach ($items as $it) {
    if ($filter !== '' && !str_contains(strtolower($it['name']), $filter)) continue;

    $recipeStmt->execute(['id' => $it['id']]);
    $lines = $recipeStmt->fetchAll();
    $reasons = [];

    foreach ($lines as $l) {
        // Rule in the running backend: stock < qty needed for ONE item  (newer rule: stock <= 0)
        if ((float)$l['stock'] < (float)$l['quantity']) {
            $reasons[] = sprintf(
                'recipe: %s has %s %s, one %s needs %s %s%s',
                $l['name'], $fmt($l['stock']), $l['unit'], $it['name'], $fmt($l['quantity']), $l['unit'],
                (float)$l['stock'] > 0 ? '  (still has stock -> only "out" under the OLD rule)' : '  (stock is 0 or below)'
            );
        }
    }
    $isFood = in_array(strtolower(trim($it['category'])), $foodCategories, true);
    if (!$isFood) {
        foreach ($perDrink as $p) {
            if ((float)$p['stock'] < (float)$p['per_drink_qty']) {
                $reasons[] = sprintf(
                    'category "%s" is treated as a DRINK, and %s (used per drink) has %s %s',
                    $it['category'], $p['name'], $fmt($p['stock']), $p['unit']
                );
            }
        }
    }

    if ($reasons || $filter !== '') {
        $shown++;
        echo "\n== {$it['name']}  [category: {$it['category']}]" . ((int)$it['available'] ? '' : '  [admin set to unavailable]') . "\n";
        if (!$lines) echo "   (no recipe lines)\n";
        foreach ($lines as $l) {
            echo sprintf("   recipe: %-28s needs %s %s | stock %s %s\n", $l['name'], $fmt($l['quantity']), $l['unit'], $fmt($l['stock']), $l['unit']);
        }
        echo $reasons ? "   => OUT OF STOCK because:\n      - " . implode("\n      - ", $reasons) . "\n" : "   => not out of stock\n";
    }
}
echo $shown ? "\nDone.\n" : "No menu item is out of stock.\n";
