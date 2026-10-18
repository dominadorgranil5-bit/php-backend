<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/payment_notifications.php';
require_method('GET');

$user = require_user();
$id = (string)($_GET['id'] ?? '');
if ($id === '') {
    json_error('id query parameter is required.', 400);
}

$stmt = db()->prepare('SELECT status, payment_status, payment_method FROM orders WHERE id = :id AND customer_id = :customer_id');
$stmt->execute(['id' => $id, 'customer_id' => $user['id']]);
$row = $stmt->fetch();

if (!$row) {
    json_error('Order not found', 404);
}

// Ask PayMongo directly whether a QR Ph payment has landed, so the order flips
// to paid even when the webhook can't reach this server (local dev / no ngrok).
// Only runs for the owner's own unpaid GCash order (checked above).
if ($row['payment_method'] === 'gcash' && $row['payment_status'] !== 'paid') {
    try {
        sync_qrph_payment_if_needed($id);
    } catch (Throwable $e) {
        error_log('[orders/status] QR Ph sync failed: ' . $e->getMessage());
    }
    $stmt->execute(['id' => $id, 'customer_id' => $user['id']]);
    $row = $stmt->fetch();
}

json_out(['status' => $row['status'], 'payment_status' => $row['payment_status']]);
