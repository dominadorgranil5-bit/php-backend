<?php
/**
 * POST {id, status} — rider moves THEIR order one step forward:
 *   picked_up → out_for_delivery → delivered
 * (accepting already sets picked_up; ready → picked_up is kept only for orders a rider
 *  claimed before that rule. pending → preparing → ready is done by staff, cancelled by
 *  staff/admin.)
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_method('POST');
$rider = require_role(['rider']);
require_once __DIR__ . '/../../lib/inventory_helpers.php';
$body = json_body();
$id = require_field($body, 'id');
$target = require_field($body, 'status');

$NEXT = ['ready' => 'picked_up', 'picked_up' => 'out_for_delivery', 'out_for_delivery' => 'delivered'];
if (!in_array($target, $NEXT, true)) {
    json_error('Invalid status for a rider.', 400);
}

$pdo = db();
$stmt = $pdo->prepare('SELECT status, rider_id, order_type FROM orders WHERE id = :id');
$stmt->execute(['id' => $id]);
$o = $stmt->fetch();
if (!$o || $o['order_type'] !== 'delivery') json_error('Order not found.', 404);
if ($o['rider_id'] !== $rider['id']) json_error('This order is assigned to another rider.', 403);
if ($o['status'] === $target) json_out(['ok' => true, 'already' => true]); // double-tap safe
if ($o['status'] === 'cancelled') json_error('This order was cancelled.', 409);
if (($NEXT[$o['status']] ?? null) !== $target) {
    json_error($o['status'] === 'preparing'
        ? 'The kitchen is still preparing this order.'
        : 'That status change is not allowed from the current status.', 409);
}

$extra = match ($target) {
    'picked_up' => ', picked_up_at = NOW()',
    'delivered' => ', delivered_at = NOW()',
    default => '',
};
$upd = $pdo->prepare("UPDATE orders SET status = :new $extra WHERE id = :id AND rider_id = :rid AND status = :old");
$upd->execute(['new' => $target, 'id' => $id, 'rid' => $rider['id'], 'old' => $o['status']]);
if ($upd->rowCount() !== 1) json_error('Order changed. Please refresh and try again.', 409);

// Delivered (GCash already paid) → deduct the recipe ingredients.
if ($target === 'delivered') {
    deduct_inventory_for_order($id);
}

json_out(['ok' => true]);
