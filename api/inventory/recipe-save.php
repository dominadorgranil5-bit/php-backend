<?php
/**
 * POST {menu_item_id, lines: [{ingredient_id, quantity, unit?: 'base'|'pump'}]}  — admin.
 * Replaces the product's whole recipe. 'pump' is converted to the ingredient's base unit
 * with ingredients.pump_ml (e.g. 2 pumps x 10 ml = 20 ml). An empty list clears the recipe.
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_method('POST');
require_role(['admin']);

$body = json_body();
$menuItemId = require_field($body, 'menu_item_id');
$lines = $body['lines'] ?? [];
if (!is_array($lines)) {
    json_error('lines must be a list.', 400);
}

$pdo = db();
$exists = $pdo->prepare('SELECT 1 FROM menu_items WHERE id = :id');
$exists->execute(['id' => $menuItemId]);
if (!$exists->fetchColumn()) {
    json_error('Menu item not found.', 404);
}

$ingStmt = $pdo->prepare('SELECT name, pump_ml FROM ingredients WHERE id = :id');
$clean = [];
foreach ($lines as $line) {
    $ingId = trim((string)($line['ingredient_id'] ?? ''));
    $qty = (float)($line['quantity'] ?? 0);
    if ($ingId === '' || $qty <= 0) {
        continue; // ignore blank rows
    }
    $ingStmt->execute(['id' => $ingId]);
    $ing = $ingStmt->fetch();
    if (!$ing) {
        json_error('Unknown ingredient in recipe.', 400);
    }
    if (($line['unit'] ?? 'base') === 'pump') {
        if ($ing['pump_ml'] === null) {
            json_error($ing['name'] . ' has no pump size set.', 400);
        }
        $qty *= (float)$ing['pump_ml'];
    }
    // Same ingredient listed twice → add together (unique key is menu_item + ingredient).
    $clean[$ingId] = ($clean[$ingId] ?? 0) + round($qty, 2);
}

$pdo->beginTransaction();
try {
    $pdo->prepare('DELETE FROM product_recipes WHERE menu_item_id = :id')->execute(['id' => $menuItemId]);
    $ins = $pdo->prepare('INSERT INTO product_recipes (menu_item_id, ingredient_id, quantity) VALUES (:m,:i,:q)');
    foreach ($clean as $ingId => $qty) {
        $ins->execute(['m' => $menuItemId, 'i' => $ingId, 'q' => $qty]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}

json_out(['ok' => true, 'lines' => count($clean)]);
