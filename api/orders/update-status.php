<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_method('POST');
require_role(['staff', 'admin']);
require_once __DIR__ . '/../../lib/inventory_helpers.php';

$body = json_body();
$id = require_field($body, 'id');
$status = require_field($body, 'status');

$valid = ['pending', 'preparing', 'ready', 'completed', 'cancelled'];
if (!in_array($status, $valid, true)) {
    json_error('Invalid status.', 400);
}

$stmt = db()->prepare('SELECT payment_method, payment_status, order_type, status, rider_id FROM orders WHERE id = :id');
$stmt->execute(['id' => $id]);
$order = $stmt->fetch();
if (!$order) {
    json_error('Order not found.', 404);
}
if ($order['payment_method'] === 'gcash' && $order['payment_status'] !== 'paid' && $status !== 'cancelled') {
    json_error('GCash orders can only be processed after PayMongo settlement and receipt delivery.', 409);
}

// Once a rider has the order (accepted / on the road / delivered) the rider owns it:
// staff can no longer move it back, complete it or cancel it from the staff screen.
if ($order['order_type'] === 'delivery' && in_array($order['status'], ['picked_up', 'out_for_delivery', 'delivered'], true)) {
    json_error('This delivery is already with the rider and can only be updated by the rider.', 409);
}
if ($order['order_type'] !== 'delivery' && in_array($status, ['picked_up', 'out_for_delivery', 'delivered'], true)) {
    json_error('Invalid status.', 400);
}

if ($status === 'completed' && $order['order_type'] === 'delivery') {
    json_error('Delivery orders are completed by the assigned rider (Delivered).', 409);
}

db()->prepare('UPDATE orders SET status = :status WHERE id = :id')
    ->execute(['status' => $status, 'id' => $id]);

// Stock is taken at checkout. Cancelling returns it; moving a cancelled order forward again takes it back
// (both are no-ops when there is nothing to do). Old open orders placed before checkout deduction
// are still handled when they are paid + completed.
if ($status === 'cancelled') {
    restore_inventory_for_order($id);
} else {
    deduct_inventory_at_checkout($id);
    if ($status === 'completed') {
        deduct_inventory_for_order($id);
    }
}

json_out(['ok' => true]);
