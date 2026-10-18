<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_method('POST');
$user = require_role(['customer']);

$body = json_body();
$id = require_field($body, 'id');
$stmt = db()->prepare(
    'SELECT status, receipt_requested_at, receipt_printed_at
     FROM orders WHERE id = :id AND customer_id = :customer_id'
);
$stmt->execute(['id' => $id, 'customer_id' => $user['id']]);
$order = $stmt->fetch();

if (!$order) {
    json_error('Order not found.', 404);
}
if (!in_array($order['status'], ['completed', 'delivered'], true)) {
    json_error('A receipt can only be requested for a completed order.', 409);
}
if ($order['receipt_printed_at'] !== null) {
    json_error('The receipt for this order has already been printed.', 409);
}
if ($order['receipt_requested_at'] === null) {
    db()->prepare('UPDATE orders SET receipt_requested_at = CURRENT_TIMESTAMP WHERE id = :id')
        ->execute(['id' => $id]);
}

json_out(['ok' => true]);