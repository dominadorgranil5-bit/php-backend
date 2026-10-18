<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_method('POST');
require_role(['staff', 'admin']);

$body = json_body();
$id = require_field($body, 'id');

$pdo = db();
$stmt = $pdo->prepare(
    'UPDATE orders
     SET receipt_requested_at = NULL, receipt_printed_at = CURRENT_TIMESTAMP
     WHERE id = :id AND receipt_requested_at IS NOT NULL AND receipt_printed_at IS NULL'
);
$stmt->execute(['id' => $id]);

if ($stmt->rowCount() === 0) {
    $check = $pdo->prepare('SELECT receipt_printed_at FROM orders WHERE id = :id');
    $check->execute(['id' => $id]);
    $order = $check->fetch();
    if (!$order) {
        json_error('Order not found.', 404);
    }
    if ($order['receipt_printed_at'] === null) {
        json_error('There is no outstanding receipt request for this order.', 409);
    }
}

json_out(['ok' => true]);