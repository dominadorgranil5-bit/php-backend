<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_method('POST');
require_once __DIR__ . '/../../lib/inventory_helpers.php';

$user = require_user();
$body = json_body();

$orderType = (string)($body['order_type'] ?? 'delivery');
$address = (string)($body['address'] ?? '');
$notes = (string)($body['notes'] ?? '');
$total = (float)($body['total'] ?? 0);
$paymentMethod = (string)($body['payment_method'] ?? 'gcash');
$items = $body['items'] ?? [];
// Walk-in orders rung up by staff/admin: the customer already paid at the counter
// (e.g. scanned the shop's own GCash QR), so the order is recorded as paid right away.
$walkInPaid = !empty($body['walk_in_paid']);
if ($walkInPaid && !in_array($user['role'], ['staff', 'admin'], true)) {
    json_error('Forbidden', 403);
}

if (!in_array($orderType, ['pickup', 'delivery', 'dinein'], true)) {
    json_error('Invalid order_type.', 400);
}
if (!in_array($paymentMethod, ['cash', 'gcash'], true)) {
    json_error('Invalid payment_method.', 400);
}
// Cash is only accepted for walk-in orders rung up at the staff POS (paid at the counter).
// Customer orders (pickup / delivery / dine-in) are online payment (GCash) only.
if ($paymentMethod === 'cash' && !$walkInPaid) {
    json_error('Cash payment is only available for walk-in orders at the POS.', 400);
}
if ($orderType === 'delivery' && trim($address) === '') {
    json_error('Delivery address is required for delivery orders.', 400);
}
if (!is_array($items) || count($items) === 0) {
    json_error('Order must include at least one item.', 400);
}

// The server owns the total: sum the line items (price is already the final per-unit
// price incl. size/add-ons) so the saved total, the PayMongo charge, the staff popup and
// the receipt all agree. Only fall back to the client's number if the items can't be summed.
$itemsTotal = 0.0;
foreach ($items as $item) {
    $itemsTotal += (float)($item['price'] ?? 0) * max(1, (int)($item['quantity'] ?? 1));
}
$total = $itemsTotal > 0 ? round($itemsTotal, 2) : round($total, 2);
if ($total <= 0) {
    json_error('Order total must be greater than zero.', 400);
}

// Refuse items whose ingredients ran out (the customer's cart may be older than the stock).
try {
    $outMap = menu_out_of_stock_map(db());
} catch (Throwable $e) {
    error_log('[inventory] out-of-stock check failed: ' . $e->getMessage());
    $outMap = [];
}
foreach ($items as $item) {
    $pid = (string)($item['product_id'] ?? '');
    if ($pid !== '' && isset($outMap[$pid])) {
        $label = trim((string)($item['name'] ?? '')) !== '' ? (string)$item['name'] : 'An item';
        json_error($label . ' is out of order (no stock). Please remove it from your order.', 409);
    }
}

$pdo = db();
$pdo->beginTransaction();

try {
    $orderId = make_uuid();
    // GCash is confirmed by PayMongo. Cash exists only for walk-ins paid at the counter.
    // Walk-ins are paid at the counter first (cash or shop GCash QR), so they are saved as
    // paid. Like every other order they start as 'pending' so staff are notified (Orders badge
    // + bell) and accept them into Preparing.
    $paymentStatus = $walkInPaid ? 'paid' : 'unpaid';
    $paymentReference = $walkInPaid ? ($paymentMethod === 'gcash' ? 'walk-in-gcash' : 'walk-in-cash') : null;
    $initialStatus = 'pending';

    $stmt = $pdo->prepare(
        'INSERT INTO orders (id, customer_id, status, order_type, address, notes, total, payment_method, payment_status, payment_reference)
         VALUES (:id, :customer_id, :status, :order_type, :address, :notes, :total, :payment_method, :payment_status, :payment_reference)'
    );
    $stmt->execute([
        'id' => $orderId,
        'customer_id' => $user['id'],
        'status' => $initialStatus,
        'order_type' => $orderType,
        'address' => $address,
        'notes' => $notes,
        'total' => $total,
        'payment_method' => $paymentMethod,
        'payment_status' => $paymentStatus,
        'payment_reference' => $paymentReference,
    ]);

    $itemStmt = $pdo->prepare(
        'INSERT INTO order_items (order_id, product_id, name, price, quantity)
         VALUES (:order_id, :product_id, :name, :price, :quantity)'
    );
    foreach ($items as $item) {
        $itemStmt->execute([
            'order_id' => $orderId,
            'product_id' => (string)($item['product_id'] ?? ''),
            'name' => (string)($item['name'] ?? ''),
            'price' => (float)($item['price'] ?? 0),
            'quantity' => max(1, (int)($item['quantity'] ?? 1)),
        ]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

// Checkout: take the ingredients (+ cup & lid) out of the shared admin/staff stock right now.
// Never throws, so a stock problem can't block the order.
deduct_inventory_at_checkout($orderId);

json_out([
    'id' => $orderId,
    'customer_id' => $user['id'],
    'status' => $initialStatus,
    'order_type' => $orderType,
    'address' => $address,
    'notes' => $notes,
    'total' => $total,
    'payment_method' => $paymentMethod,
    'payment_status' => $paymentStatus,
], 201);
