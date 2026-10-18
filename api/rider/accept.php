<?php
/** POST {id} — "Accept Delivery". Atomic, so two riders can't claim the same order. */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_method('POST');
$rider = require_role(['rider']);
$id = require_field(json_body(), 'id');
$pdo = db();

$stmt = $pdo->prepare(
    "UPDATE orders SET rider_id = :rid, rider_accepted_at = NOW()
     WHERE id = :id AND order_type = 'delivery' AND rider_id IS NULL
       AND status IN ('preparing','ready')
       AND (payment_method != 'gcash' OR payment_status = 'paid')"
);
$stmt->execute(['rid' => $rider['id'], 'id' => $id]);
if ($stmt->rowCount() === 1) {
    json_out(['ok' => true]);
}

$chk = $pdo->prepare('SELECT rider_id, status, order_type FROM orders WHERE id = :id');
$chk->execute(['id' => $id]);
$o = $chk->fetch();
if (!$o || $o['order_type'] !== 'delivery') json_error('Order not found.', 404);
if ($o['rider_id'] === $rider['id']) json_out(['ok' => true, 'already_yours' => true]);
if ($o['rider_id'] !== null) json_error('Another rider already accepted this order.', 409);
json_error('This order is no longer available.', 409);
