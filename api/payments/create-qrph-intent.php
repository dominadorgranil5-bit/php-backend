<?php
// api/payments/create-qrph-intent.php
//
// Called by the app right after an order is placed with payment_method='gcash',
// when the customer pays via QR instead of the direct GCash-app redirect
// (create-gcash-source.php). This is PayMongo's "QR Ph" dynamic QR: a
// single-use code, generated per order through the Payment Intent workflow,
// with the exact peso amount already encoded into it. The customer scans it
// with the GCash app (or any other bank/e-wallet app that supports QR Ph)
// and the amount is pre-filled — no manual entry, no source-redirect page.
//
// Flow, all done server-side with the secret key (nothing sensitive is
// collected here, so there's no need to round-trip a public key to the
// client the way PayMongo's docs show for card payments):
//   1. Create a Payment Intent scoped to payment_method_allowed = ['qrph'].
//   2. Create a bare Payment Method of type 'qrph'.
//   3. Attach the Payment Method to the Intent — this is the call that
//      actually generates the QR code image.
//   4. Return the QR image (a ready-to-use "data:image/png;base64,..." URI)
//      to the app so it can just be dropped into an <Image>/<img>.
//
// PayMongo expires an unscanned QR Ph code after 30 minutes (event:
// qrph.expired — handled in webhook.php). The app can call this endpoint
// again for the same order to get a fresh code; each call makes a new
// Payment Intent, so there's nothing to "renew" server-side.

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/paymongo.php';
require_method('POST');

$user = require_user();
$body = json_body();
$orderId = require_field($body, 'order_id');

$stmt = db()->prepare('SELECT id, customer_id, total, payment_method, payment_status FROM orders WHERE id = :id');
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
if ($order['payment_status'] === 'paid') {
    json_error('This order is already paid.', 400);
}

$secretKey = paymongo_secret_or_fail(); // refuses a live key while PAYMONGO_MODE=test
$amountInCentavos = (int)round((float)$order['total'] * 100);
$shortId = strtoupper(substr($orderId, 0, 8));

// 1. Create the Payment Intent.
[$status, $data, $err] = paymongo_post('/payment_intents', [
    'data' => [
        'attributes' => [
            'amount' => $amountInCentavos,
            'currency' => 'PHP',
            'payment_method_allowed' => ['qrph'],
            'description' => "Order #$shortId",
        ],
    ],
], $secretKey);

if ($err !== null || !$data || $status < 200 || $status >= 300) {
    json_error(paymongo_error_detail($data, $err, 'PayMongo error creating payment intent'), 400);
}

$intentId = $data['data']['id'];
$clientKey = $data['data']['attributes']['client_key'];

// 2. Create a bare QR Ph payment method (no billing/card data needed).
[$status, $data, $err] = paymongo_post('/payment_methods', [
    'data' => [
        'attributes' => [
            'type' => 'qrph',
        ],
    ],
], $secretKey);

if ($err !== null || !$data || $status < 200 || $status >= 300) {
    json_error(paymongo_error_detail($data, $err, 'PayMongo error creating payment method'), 400);
}

$paymentMethodId = $data['data']['id'];

// 3. Attach — the QR image comes back on this response.
[$status, $data, $err] = paymongo_post("/payment_intents/$intentId/attach", [
    'data' => [
        'attributes' => [
            'payment_method' => $paymentMethodId,
            'client_key' => $clientKey,
        ],
    ],
], $secretKey);

if ($err !== null || !$data || $status < 200 || $status >= 300) {
    json_error(paymongo_error_detail($data, $err, 'PayMongo error generating QR code'), 400);
}

$qrImage = $data['data']['attributes']['next_action']['code']['image_url'] ?? null;
if (!$qrImage) {
    json_error('PayMongo did not return a QR code image.', 502);
}

// Store the payment intent id (mirrored into payment_reference, same as the
// source-based flow does with paymongo_source_id) so:
//   - webhook.php can find this order when payment.paid / qrph.expired arrives
//   - staff see a reference on the existing order card with no extra UI work
$update = db()->prepare(
    'UPDATE orders SET paymongo_payment_intent_id = :pid, payment_reference = :pid2 WHERE id = :id'
);
$update->execute(['pid' => $intentId, 'pid2' => $intentId, 'id' => $orderId]);

json_out([
    'qr_image' => $qrImage, // "data:image/png;base64,..." — drop straight into <Image>/<img>
    'payment_intent_id' => $intentId,
    'expires_in_seconds' => 1800, // PayMongo's default QR Ph expiry; used for the app's countdown
    'test_mode' => paymongo_key_mode() === 'test',
]);
