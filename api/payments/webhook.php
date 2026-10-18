<?php
// api/payments/webhook.php
//
// PayMongo calls this directly (no user session) whenever something happens
// to a source/payment/QR code. There are now two GCash payment paths, and
// this file listens for both:
//
//   Path A — direct GCash-app redirect (create-gcash-source.php), a
//   PayMongo "source":
//     1. source.chargeable — the customer approved payment in GCash. We must
//        now actually create a Payment against that source to capture the money.
//     2. payment.paid — the payment succeeded. Matched by paymongo_source_id.
//     3. payment.failed — declined/failed. Matched by paymongo_source_id.
//
//   Path B — QR Ph dynamic QR (create-qrph-intent.php), a PayMongo
//   "payment intent":
//     4. payment.paid — the customer scanned and paid. Matched by
//        paymongo_payment_intent_id (the paid Payment resource carries the
//        originating payment_intent_id in its attributes).
//     5. qrph.expired — the code wasn't scanned within PayMongo's 30-minute
//        window. Matched by paymongo_payment_intent_id.
//
// Both paths converge on the same two outcomes: mark the order paid, or
// mark it 'payment_failed' via lib/payment_notifications.php (which also
// emails the customer) — see that file for why a failed GCash-style payment
// can't just delete the order.
//
// Register this URL in PayMongo Dashboard > Developers > Webhooks:
//   https://your-domain.com/api/payments/webhook.php
// Subscribe it to: source.chargeable, payment.paid, payment.failed, qrph.expired
// PayMongo will show you a signing secret when you create the webhook — set
// it as PAYMONGO_WEBHOOK_SECRET in .env.
//
// IMPORTANT: this endpoint deliberately skips require_user() — PayMongo
// can't send a session token. verify_signature() below is what secures it.

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/payment_notifications.php';

function verify_signature(string $payload, ?string $signatureHeader, string $webhookSecret): bool
{
    if (!$signatureHeader) {
        return false;
    }
    // Format: t=<timestamp>,te=<test_signature>,li=<live_signature>
    $parts = [];
    foreach (explode(',', $signatureHeader) as $piece) {
        [$k, $v] = array_pad(explode('=', $piece, 2), 2, '');
        $parts[$k] = $v;
    }
    $signedPayload = ($parts['t'] ?? '') . '.' . $payload;
    $computed = hash_hmac('sha256', $signedPayload, $webhookSecret);

    // li = live signature, te = test-mode signature — check whichever PayMongo sent.
    return hash_equals($computed, $parts['li'] ?? '') || hash_equals($computed, $parts['te'] ?? '');
}

$payload = file_get_contents('php://input') ?: '';
$signature = $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? null;
$webhookSecret = config('PAYMONGO_WEBHOOK_SECRET', '');

if (!verify_signature($payload, $signature, $webhookSecret)) {
    json_error('Invalid signature', 401);
}

$event = json_decode($payload, true) ?? [];
$type = $event['data']['attributes']['type'] ?? null;
$resource = $event['data']['attributes']['data'] ?? null;

if ($type === 'source.chargeable') {
    $sourceId = $resource['id'] ?? null;
    $amount = $resource['attributes']['amount'] ?? null;

    if ($sourceId && $amount !== null) {
        // Capture the payment now that the source is chargeable.
        $secretKey = config('PAYMONGO_SECRET_KEY', '');
        $capturePayload = json_encode([
            'data' => [
                'attributes' => [
                    'amount' => $amount,
                    'currency' => 'PHP',
                    'source' => ['id' => $sourceId, 'type' => 'source'],
                ],
            ],
        ]);

        $ch = curl_init('https://api.paymongo.com/v1/payments');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $capturePayload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Basic ' . base64_encode($secretKey . ':'),
            ],
        ]);
        $captureRaw = curl_exec($ch);
        curl_close($ch);

        // The capture call itself can come back "failed" (e.g. the customer's
        // GCash balance changed between approval and capture) — don't wait
        // for a separate payment.failed event that may never arrive.
        $capturePayment = json_decode((string)$captureRaw, true)['data'] ?? null;
        $captureStatus = $capturePayment['attributes']['status'] ?? null;
        if ($captureStatus === 'failed') {
            $stmt = db()->prepare('SELECT id FROM orders WHERE paymongo_source_id = :sid');
            $stmt->execute(['sid' => $sourceId]);
            $order = $stmt->fetch();
            if ($order) {
                mark_gcash_payment_failed($order['id']);
            }
        }
    }
}

if ($type === 'payment.paid') {
    // Path A: source-based GCash (direct app redirect).
    $sourceId = $resource['attributes']['source']['id'] ?? null;
    // Path B: QR Ph — the Payment resource carries the originating intent id.
    $paymentIntentId = $resource['attributes']['payment_intent_id'] ?? null;

    $paymentReference = trim((string)(
        $resource['attributes']['external_reference_number']
        ?? $resource['attributes']['metadata']['pm_reference_number']
        ?? ''
    ));

    if ($sourceId) {
        $stmt = db()->prepare('SELECT id FROM orders WHERE paymongo_source_id = :sid');
        $stmt->execute(['sid' => $sourceId]);
        $order = $stmt->fetch();
    } elseif ($paymentIntentId) {
        $stmt = db()->prepare('SELECT id FROM orders WHERE paymongo_payment_intent_id = :pid');
        $stmt->execute(['pid' => $paymentIntentId]);
        $order = $stmt->fetch();
    }

    if (!empty($order['id'])) {
        mark_gcash_payment_paid((string)$order['id'], $paymentReference);
    }
}

if ($type === 'payment.failed') {
    $sourceId = $resource['attributes']['source']['id'] ?? null;
    $paymentIntentId = $resource['attributes']['payment_intent_id'] ?? null;

    if ($sourceId) {
        $stmt = db()->prepare('SELECT id FROM orders WHERE paymongo_source_id = :sid');
        $stmt->execute(['sid' => $sourceId]);
        $order = $stmt->fetch();
        if ($order) {
            mark_gcash_payment_failed($order['id']);
        }
    } elseif ($paymentIntentId) {
        $stmt = db()->prepare('SELECT id FROM orders WHERE paymongo_payment_intent_id = :pid');
        $stmt->execute(['pid' => $paymentIntentId]);
        $order = $stmt->fetch();
        if ($order) {
            mark_gcash_payment_failed($order['id']);
        }
    }
}

if ($type === 'qrph.expired') {
    // Resource here is the payment_intent itself (id like "pi_xxx"), not a
    // nested "data" wrapper — unlike payment.paid/failed above.
    $paymentIntentId = $resource['id'] ?? null;
    if ($paymentIntentId) {
        $stmt = db()->prepare('SELECT id FROM orders WHERE paymongo_payment_intent_id = :pid');
        $stmt->execute(['pid' => $paymentIntentId]);
        $order = $stmt->fetch();
        if ($order) {
            // Same treatment as a failed/declined payment: mark it so it
            // never surfaces to staff as a real order, and email the
            // customer. If they still want to pay, the app can call
            // create-qrph-intent.php again for a fresh code — but that only
            // helps if the order itself is still usable, so this only fires
            // while the order is still unpaid/unflagged (see
            // mark_gcash_payment_failed's own guard).
            mark_gcash_payment_failed($order['id']);
        }
    }
}

json_out(['received' => true]);
