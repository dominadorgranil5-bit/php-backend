<?php
/** GET — every menu item with its recipe lines (base-unit quantities). staff/admin. */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_method('GET');
require_role(['staff', 'admin']);

$pdo = db();
$items = $pdo->query('SELECT id, name, category FROM menu_items ORDER BY category, name')->fetchAll();
$lines = $pdo->query(
    'SELECT r.menu_item_id, r.ingredient_id, r.quantity, i.name AS ingredient_name, i.unit
     FROM product_recipes r JOIN ingredients i ON i.id = r.ingredient_id
     ORDER BY i.name'
)->fetchAll();

$byItem = [];
foreach ($lines as $l) {
    $byItem[$l['menu_item_id']][] = [
        'ingredient_id' => $l['ingredient_id'],
        'ingredient_name' => $l['ingredient_name'],
        'unit' => $l['unit'],
        'quantity' => (float)$l['quantity'],
    ];
}
foreach ($items as &$it) {
    $it['lines'] = $byItem[$it['id']] ?? [];
}
unset($it);

json_out($items);
