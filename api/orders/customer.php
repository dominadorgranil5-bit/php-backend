<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/orders_helpers.php';
require_method('GET');

$user = require_user();

// rider_name: once a rider accepts a delivery, the customer sees who is bringing it.
$stmt = db()->prepare(
    'SELECT o.*, r.name AS rider_name
     FROM orders o
     LEFT JOIN users r ON r.id = o.rider_id
     WHERE o.customer_id = :id
     ORDER BY o.created_at DESC'
);
$stmt->execute(['id' => $user['id']]);
$orders = $stmt->fetchAll();

foreach ($orders as &$row) {
    $row['rider_name'] = $row['rider_name'] ?: null;
}
unset($row);

json_out(attach_order_items($orders));
