<?php
/**
 * Cold Brew counted in PACKS: 1 pack = 15 L = 15,000 ml, every coffee-based order uses 150 ml.
 *
 *   10 packs x 15,000 ml = 150,000 ml = 1,000 coffee orders.
 *
 * Cold Brew stays ONE stock, kept in ml (so recipes are typed in ml), but it is restocked and counted
 * in packs (pc) in Admin > Inventory. Run once (safe to run again):  php migrate-coffee-pack.php
 *
 *   1. Cold Brew: unit ml, pack_size = 15000, no linked pack stock.
 *   2. If an earlier version of this script created a separate "Coffee Pack" stock, its packs are
 *      folded into Cold Brew and that stock is removed.
 *   3. If Cold Brew has no stock yet, opening stock = 10 packs.
 *   4. Every recipe line using Cold Brew = 150 ml.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';

const PACK_ML = 15000;
const SERVING_ML = 150;
const OPENING_PACKS = 10;

$pdo = db();
$find = $pdo->prepare('SELECT id, stock, pack_ingredient_id, pack_used FROM ingredients WHERE name = :n');

$find->execute(['n' => 'Cold Brew']);
$cb = $find->fetch();
$pdo->beginTransaction();
if (!$cb) {
    $cbId = make_uuid();
    $pdo->prepare('INSERT INTO ingredients (id, name, unit, stock, low_stock_level, pack_size) VALUES (:id, "Cold Brew", "ml", 0, :l, :p)')
        ->execute(['id' => $cbId, 'l' => 2 * PACK_ML, 'p' => PACK_ML]);
    $cb = ['id' => $cbId, 'stock' => 0, 'pack_ingredient_id' => null, 'pack_used' => 0];
    echo "Cold Brew created.\n";
}
$cbId = $cb['id'];
$stockMl = (float)$cb['stock'];

// 2) fold the old "Coffee Pack" stock into Cold Brew
$find->execute(['n' => 'Coffee Pack']);
$old = $find->fetch();
if ($old) {
    $stockMl = round((float)$old['stock'] * PACK_ML - (float)$cb['pack_used'], 2);
    $pdo->prepare('UPDATE ingredients SET pack_ingredient_id = NULL WHERE id = :id')->execute(['id' => $cbId]);
    $pdo->prepare('DELETE FROM ingredients WHERE id = :id')->execute(['id' => $old['id']]);
    echo "Old Coffee Pack stock folded into Cold Brew.\n";
} elseif ($stockMl <= 0) {
    $stockMl = OPENING_PACKS * PACK_ML;
    $pdo->prepare('INSERT INTO inventory_movements (ingredient_id, change_qty, reason, note) VALUES (:i, :c, "adjustment", "Opening stock: 10 packs")')
        ->execute(['i' => $cbId, 'c' => $stockMl - (float)$cb['stock']]);
    echo "Opening stock: " . OPENING_PACKS . " packs.\n";
}

// 1) Cold Brew: ml, counted in packs
$pdo->prepare('UPDATE ingredients SET unit = "ml", stock = :s, pack_ingredient_id = NULL, pack_size = :p, pack_used = 0, low_stock_level = :l WHERE id = :id')
    ->execute(['s' => $stockMl, 'p' => PACK_ML, 'l' => 2 * PACK_ML, 'id' => $cbId]);
$pdo->commit();
echo "Cold Brew: " . $stockMl . " ml = " . round($stockMl / PACK_ML, 2) . " packs (1 pack = " . PACK_ML . " ml). Alert at 2 packs.\n";

// 4) recipes: every COFFEE-based menu item gets Cold Brew = 150 ml per order (line updated, or added if missing)
$items = $pdo->query("SELECT id, name FROM menu_items WHERE LOWER(category) LIKE '%coffee%'")->fetchAll();
$up = $pdo->prepare('INSERT INTO product_recipes (menu_item_id, ingredient_id, quantity) VALUES (:m, :i, :q)
                     ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)');
foreach ($items as $it) {
    $up->execute(['m' => $it['id'], 'i' => $cbId, 'q' => SERVING_ML]);
    echo "Cold Brew " . SERVING_ML . " ml -> {$it['name']}\n";
}
// any other recipe already using Cold Brew is set to 150 ml too
$pdo->prepare('UPDATE product_recipes SET quantity = :q WHERE ingredient_id = :i')->execute(['q' => SERVING_ML, 'i' => $cbId]);
echo "Done.\n";
