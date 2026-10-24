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
// Walk-in cash: how much the customer handed over (the receipt shows Cash and Change).
$cashReceived = isset($body['cash_received']) ? round((float)$body['cash_received'], 2) : null;
// POS delivery: who the rider will call (walk-in customers have no account, so staff
// records the name/contact at the counter). Online orders use the account's own details.
$customerName = trim((string)($body['customer_name'] ?? ''));
$contactNumber = trim((string)($body['contact_number'] ?? ''));
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
// POS delivery: the walk-in customer has no account behind the order, so staff must
// record who the rider will call and hand the order to.
if ($orderType === 'delivery' && $walkInPaid) {
    if ($customerName === '') {
        json_error('Customer name is required for delivery orders.', 400);
    }
    $contactDigits = preg_replace('/\D/', '', $contactNumber);
    if (!preg_match('/^[0-9+\-() ]+$/', $contactNumber) || strlen($contactDigits) < 7 || strlen($contactDigits) > 15) {
        json_error('A valid contact number is required for delivery orders.', 400);
    }
}
// Reservations (customer dine-in) must carry the date & time in the notes. Staff walk-in dine-in is exempt.
if ($orderType === 'dinein' && !$walkInPaid && trim($notes) === '') {
    json_error('Please enter the date and time of your reservation in the notes.', 400);
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
    $cashToStore = ($walkInPaid && $paymentMethod === 'cash' && $cashReceived !== null && $cashReceived >= $total) ? $cashReceived : null;
    // Single source of truth for where the order came from — derived server-side, never
    // trusted from the client. Online delivery snapshots the account's phone so the rider
    // always has a valid number even if the customer later edits their profile.
    $orderSource = $walkInPaid ? 'pos' : 'online';
    $contactToStore = $orderType === 'delivery'
        ? ($contactNumber !== '' ? $contactNumber : (string)$user['phone'])
        : '';
    $nameToStore = $customerName;

    $stmt = $pdo->prepare(
        'INSERT INTO orders (id, customer_id, status, order_type, address, notes, total, payment_method, payment_status, payment_reference, cash_received, order_source, contact_number, customer_name)
         VALUES (:id, :customer_id, :status, :order_type, :address, :notes, :total, :payment_method, :payment_status, :payment_reference, :cash_received, :order_source, :contact_number, :customer_name)'
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
        'cash_received' => $cashToStore,
        'order_source' => $orderSource,
        'contact_number' => $contactToStore,
        'customer_name' => $nameToStore,
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
    'order_source' => $orderSource,
    'address' => $address,
    'customer_name' => $nameToStore,
    'contact_number' => $contactToStore,
    'notes' => $notes,
    'total' => $total,
    'payment_method' => $paymentMethod,
    'payment_status' => $paymentStatus,
    'cash_received' => $cashToStore ?? null,
], 201);
