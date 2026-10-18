<?php
// api/payments/create-gcash-source.php
//
// Called by the app right after an order is placed with payment_method='gcash'.
// Creates a PayMongo "source" (their term for a checkout session for
// e-wallets like GCash) and returns the checkout_url the app should open
// in-browser. Direct port of the old create-gcash-source Supabase edge function.

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/paymongo.php';
require_method('POST');

$user = require_user();
$body = json_body();
$orderId = require_field($body, 'order_id');
$platform = isset($body['platform']) && in_array($body['platform'], ['web', 'native'], true)
    ? $body['platform']
    : 'native';

$stmt = db()->prepare('SELECT id, customer_id, total, payment_method FROM orders WHERE id = :id');
$stmt->execute(['id' => $orderId]);
$order = $stmt->fetch();

if (!$order) {
    json_error('Order not found', 404);
}
if ($order['customer_id'] !== $user['id']) {
    json_error('This order does not belong to you', 403);
}
if ($order['payment_method'] !== 'gcash') {
    json_error('Order is not set to GCash payment', 400);
}

$secretKey = paymongo_secret_or_fail(); // refuses a live key while PAYMONGO_MODE=test
$baseUrl = rtrim(config('APP_BASE_URL', ''), '/');
$amountInCentavos = (int)round((float)$order['total'] * 100);

$payload = json_encode([
    'data' => [
        'attributes' => [
            'amount' => $amountInCentavos,
            'currency' => 'PHP',
            'type' => 'gcash',
            // Must be plain http(s) URLs — PayMongo rejects custom schemes
            // (e.g. "drewscafe://...") with "redirect.failed format is invalid".
            // This routes through payment-redirect.php, which then hands off
            // to the app via APP_SCHEME.
            'redirect' => [
                'success' => "$baseUrl/payments/payment-redirect.php?status=success&order_id=$orderId&platform=$platform",
                'failed' => "$baseUrl/payments/payment-redirect.php?status=failed&order_id=$orderId&platform=$platform",
            ],
        ],
    ],
]);

$ch = curl_init('https://api.paymongo.com/v1/sources');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Basic ' . base64_encode($secretKey . ':'),
    ],
]);
$raw = curl_exec($ch);
$httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($raw === false) {
    json_error("PayMongo request failed: $curlError", 502);
}

$paymongoData = json_decode($raw, true);

if ($httpStatus < 200 || $httpStatus >= 300) {
    $detail = $paymongoData['errors'][0]['detail'] ?? 'PayMongo error creating source';
    json_error($detail, 400);
}

$source = $paymongoData['data'];

// Store the source id (also mirrored into payment_reference so staff see
// it on the existing order card without any extra UI work).
$update = db()->prepare(
    'UPDATE orders SET paymongo_source_id = :sid, payment_reference = :sid2 WHERE id = :id'
);
$update->execute(['sid' => $source['id'], 'sid2' => $source['id'], 'id' => $orderId]);

json_out([
    'checkout_url' => $source['attributes']['redirect']['checkout_url'],
    'test_mode' => paymongo_key_mode() === 'test',
]);
