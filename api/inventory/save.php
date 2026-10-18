<?php
/** POST {id?, name, unit, low_stock_level?, pump_ml?, per_drink_qty?, dough_size?, box_size?, pack_ingredient_id?, pack_size?, stock?}  — admin. stock is only used on create (opening count). */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_method('POST');
$user = require_role(['admin']);

$body = json_body();
$name = require_field($body, 'name');
$unit = (string)($body['unit'] ?? 'ml');
if (!in_array($unit, ['ml', 'g', 'pc'], true)) {
    json_error('Unit must be ml, g or pc.', 400);
}
$low = max(0, (float)($body['low_stock_level'] ?? 0));
$pump = isset($body['pump_ml']) && $body['pump_ml'] !== '' ? (float)$body['pump_ml'] : null;
if ($pump !== null && $pump <= 0) {
    json_error('Pump size must be greater than zero.', 400);
}
$perDrink = max(0, (float)($body['per_drink_qty'] ?? 0));
// Pizza dough size in inches (14 / 16 / 20...). null = not a pizza dough.
$dough = isset($body['dough_size']) && $body['dough_size'] !== '' ? (int)$body['dough_size'] : null;
if ($dough !== null && ($dough < 1 || $dough > 99)) {
    json_error('Invalid dough size.', 400);
}
// Pizza box size in inches (14 / 16 / 20...). null = not a pizza box. Deducted 1 per pizza of that size.
$box = isset($body['box_size']) && $body['box_size'] !== '' ? (int)$body['box_size'] : null;
if ($box !== null && ($box < 1 || $box > 99)) {
    json_error('Invalid box size.', 400);
}
if ($dough !== null && $box !== null) {
    json_error('A stock cannot be both pizza dough and a pizza box.', 400);
}
if ($dough !== null || $box !== null) {
    $perDrink = 0; // dough / box are deducted per pizza size, never per drink
}
$id = trim((string)($body['id'] ?? ''));

// Pack: "when this stock is used up, take 1 pack out of another stock" (e.g. Cold Brew -> Coffee Pack).
$packIng = trim((string)($body['pack_ingredient_id'] ?? ''));
$packIng = $packIng === '' ? null : $packIng;
$packSize = isset($body['pack_size']) && $body['pack_size'] !== '' ? round((float)$body['pack_size'], 2) : null;
if ($packIng !== null) {
    if ($packSize === null || $packSize <= 0) {
        json_error('Enter how much one pack makes (greater than zero).', 400);
    }
    if ($packIng === $id) {
        json_error('A stock cannot be its own pack.', 400);
    }
    if ($dough !== null || $box !== null || $perDrink > 0) {
        json_error('Dough, box and per-drink stocks cannot use packs.', 400);
    }
} elseif ($packSize !== null && $packSize > 0 && $unit !== 'pc' && $dough === null && $box === null && $perDrink <= 0) {
    // Counted in packs: stock is kept in ml/g (recipes use ml), but it is restocked / counted in packs
    // (e.g. Cold Brew, 1 pack = 15000 ml).
} else {
    $packSize = null;
}

$pdo = db();
$dup = $pdo->prepare('SELECT id FROM ingredients WHERE name = :n AND id != :id');
$dup->execute(['n' => $name, 'id' => $id]);
if ($dup->fetchColumn()) {
    json_error('An ingredient with that name already exists.', 409);
}
if ($dough !== null) {
    // One dough stock per size, otherwise a pizza would not know which one to deduct from.
    $dupDough = $pdo->prepare('SELECT name FROM ingredients WHERE dough_size = :s AND id != :id');
    $dupDough->execute(['s' => $dough, 'id' => $id]);
    $existing = $dupDough->fetchColumn();
    if ($existing) {
        json_error('There is already a ' . $dough . '" dough stock (' . $existing . '). Restock that one instead.', 409);
    }
}

if ($packIng !== null) {
    $packExists = $pdo->prepare('SELECT 1 FROM ingredients WHERE id = :id');
    $packExists->execute(['id' => $packIng]);
    if (!$packExists->fetchColumn()) {
        json_error('The pack stock was not found.', 404);
    }
}
if ($box !== null) {
    // One box stock per size, otherwise a pizza would not know which one to deduct from.
    $dupBox = $pdo->prepare('SELECT name FROM ingredients WHERE box_size = :s AND id != :id');
    $dupBox->execute(['s' => $box, 'id' => $id]);
    $existingBox = $dupBox->fetchColumn();
    if ($existingBox) {
        json_error('There is already a ' . $box . '" box stock (' . $existingBox . '). Restock that one instead.', 409);
    }
}

if ($id === '') {
    $id = make_uuid();
    $stock = max(0, (float)($body['stock'] ?? 0));
    $pdo->beginTransaction();
    $pdo->prepare(
        'INSERT INTO ingredients (id, name, unit, stock, low_stock_level, pump_ml, per_drink_qty, dough_size, box_size, pack_ingredient_id, pack_size) VALUES (:id,:n,:u,:s,:l,:p,:d,:ds,:bs,:pi,:ps)'
    )->execute(['id' => $id, 'n' => $name, 'u' => $unit, 's' => $stock, 'l' => $low, 'p' => $pump, 'd' => $perDrink, 'ds' => $dough, 'bs' => $box, 'pi' => $packIng, 'ps' => $packSize]);
    if ($stock > 0) {
        $pdo->prepare(
            'INSERT INTO inventory_movements (ingredient_id, change_qty, reason, note, created_by)
             VALUES (:i, :c, "adjustment", "Opening stock", :u)'
        )->execute(['i' => $id, 'c' => $stock, 'u' => $user['id']]);
    }
    $pdo->commit();
    json_out(['ok' => true, 'id' => $id], 201);
}

$pdo->beginTransaction();
$pdo->prepare(
    'UPDATE ingredients SET name = :n, unit = :u, low_stock_level = :l, pump_ml = :p, per_drink_qty = :d, dough_size = :ds, box_size = :bs, pack_ingredient_id = :pi, pack_size = :ps, pack_used = IF(:pi2 IS NULL, 0, pack_used) WHERE id = :id'
)->execute(['n' => $name, 'u' => $unit, 'l' => $low, 'p' => $pump, 'd' => $perDrink, 'ds' => $dough, 'bs' => $box, 'pi' => $packIng, 'pi2' => $packIng, 'ps' => $packSize, 'id' => $id]);
if ($perDrink > 0 || $dough !== null || $box !== null) {
    // Per-drink / dough / box items must not also be in a recipe, or they'd be deducted twice.
    $pdo->prepare('DELETE FROM product_recipes WHERE ingredient_id = :id')->execute(['id' => $id]);
}
$pdo->commit();
json_out(['ok' => true, 'id' => $id]);
