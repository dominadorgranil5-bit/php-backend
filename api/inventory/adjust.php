<?php
/**
 * POST {ingredient_id, mode: 'add'|'set', amount, note?}   — staff/admin
 *   add: restock — adds `amount` (> 0) to the current stock
 *   set: physical count — sets stock to exactly `amount` (>= 0), logs the difference
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_method('POST');
$user = require_role(['staff', 'admin']);

$body = json_body();
$id = require_field($body, 'ingredient_id');
$mode = (string)($body['mode'] ?? 'add');
$amount = (float)($body['amount'] ?? 0);
$note = mb_substr(trim((string)($body['note'] ?? '')), 0, 255);

if (!in_array($mode, ['add', 'set'], true)) {
    json_error('Invalid mode.', 400);
}
if ($mode === 'add' && $amount <= 0) {
    json_error('Enter an amount greater than zero.', 400);
}
if ($mode === 'set' && $amount < 0) {
    json_error('Stock count cannot be negative.', 400);
}

$pdo = db();
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('SELECT stock, pack_ingredient_id, pack_size FROM ingredients WHERE id = :id FOR UPDATE');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    if (!$row) {
        $pdo->rollBack();
        json_error('Ingredient not found.', 404);
    }
    if ($row['pack_ingredient_id'] !== null && (float)$row['pack_size'] > 0) {
        // Cold Brew etc. is derived from its pack stock - restock / count the PACK, not the liquid.
        $pdo->rollBack();
        json_error('This stock is computed from its packs. Restock or count the pack stock (e.g. Coffee Pack) instead.', 409);
    }
    $current = $row['stock'];

    $delta = $mode === 'add' ? $amount : $amount - (float)$current;
    $pdo->prepare('UPDATE ingredients SET stock = stock + :d WHERE id = :id')
        ->execute(['d' => $delta, 'id' => $id]);
    if ($delta != 0.0) {
        $pdo->prepare(
            'INSERT INTO inventory_movements (ingredient_id, change_qty, reason, note, created_by)
             VALUES (:i, :c, :r, :n, :u)'
        )->execute([
            'i' => $id, 'c' => $delta, 'r' => $mode === 'add' ? 'restock' : 'adjustment',
            'n' => $note !== '' ? $note : null, 'u' => $user['id'],
        ]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}

json_out(['ok' => true, 'stock' => (float)$current + $delta]);
