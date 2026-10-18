<?php
// api/payments/payment-redirect.php
//
// Browser bridge PayMongo redirects to once the GCash checkout finishes.
// On top of handing off back to the app via APP_SCHEME, this ALSO captures
// the payment directly here — instead of relying only on webhook.php.
//
// Why: webhook.php is called by PayMongo's own servers, so it needs this
// backend reachable over the public internet (ngrok, or a real domain in
// prod). This page, on the other hand, is loaded by the CUSTOMER'S OWN
// phone/browser right after checkout — the same device that already
// reached this backend to load the checkout page in the first place — so
// it works fine over a local LAN with no tunnel needed.
//
// Safe to keep both: a PayMongo source can only be captured once, so once
// you're on a public domain and the webhook is wired up too, this just
// becomes a harmless no-op (order's already paid by the time this runs).
//
// This returns HTML, not JSON — it does not use bootstrap.php's JSON error handling.

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/payment_notifications.php';

$appScheme = config('APP_SCHEME', 'drewscafe');
$platform = isset($_GET['platform']) && in_array($_GET['platform'], ['web', 'native'], true)
    ? $_GET['platform']
    : 'native';
$status = ($_GET['status'] ?? '') === 'success' ? 'success' : 'failed';
$orderId = $_GET['order_id'] ?? '';

if ($status === 'success' && $orderId !== '') {
    try {
        capture_gcash_payment_if_needed($orderId);
    } catch (Throwable $e) {
        // Don't block the redirect just because capture had a hiccup —
        // the app's own pollPaymentStatus() (and the real webhook, once
        // this backend is public) are still there as a backstop.
        error_log('[payment-redirect] capture failed: ' . $e->getMessage());
    }
} elseif ($status === 'failed' && $orderId !== '') {
    // PayMongo itself sent the customer to the "failed" redirect (declined,
    // or they backed out of the GCash checkout). No order should go through
    // for this — mark it payment_failed and email the customer.
    try {
        mark_gcash_payment_failed($orderId);
    } catch (Throwable $e) {
        error_log('[payment-redirect] failure handling errored: ' . $e->getMessage());
    }
}

/**
 * Mirrors webhook.php's source.chargeable + payment.paid handling, but run
 * synchronously right here instead of waiting for PayMongo to call us back.
 */
function capture_gcash_payment_if_needed(string $orderId): void
{
    $stmt = db()->prepare('SELECT total, payment_status, paymongo_source_id FROM orders WHERE id = :id');
    $stmt->execute(['id' => $orderId]);
    $order = $stmt->fetch();

    if (!$order || $order['payment_status'] === 'paid' || !$order['paymongo_source_id']) {
        return; // not found, already paid, or not a GCash order — nothing to do
    }

    $secretKey = config('PAYMONGO_SECRET_KEY', '');
    $sourceId = $order['paymongo_source_id'];

    // The source can still be "pending" for a beat right as PayMongo redirects
    // us back — poll it briefly instead of giving up on the first check.
    $sourceStatus = null;
    for ($i = 0; $i < 5; $i++) {
        $ch = curl_init("https://api.paymongo.com/v1/sources/$sourceId");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Basic ' . base64_encode($secretKey . ':')],
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
        $source = json_decode((string)$raw, true)['data'] ?? null;
        $sourceStatus = $source['attributes']['status'] ?? null;

        if ($sourceStatus === 'chargeable') {
            break;
        }
        if ($sourceStatus === 'consumed') {
            return; // already captured elsewhere (e.g. the webhook beat us to it)
        }
        if ($sourceStatus === 'expired' || $sourceStatus === 'cancelled') {
            mark_gcash_payment_failed($orderId); // the customer backed out / it timed out
            return;
        }
        usleep(700_000); // 0.7s
    }

    if ($sourceStatus !== 'chargeable') {
        return; // still not ready — app's own polling will catch it if it lands later
    }

    // Capture: create the actual Payment against this now-chargeable source.
    $amountInCentavos = (int)round((float)$order['total'] * 100);
    $payload = json_encode([
        'data' => ['attributes' => [
            'amount' => $amountInCentavos,
            'currency' => 'PHP',
            'source' => ['id' => $sourceId, 'type' => 'source'],
        ]],
    ]);

    $ch = curl_init('https://api.paymongo.com/v1/payments');
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
    curl_close($ch);
    $payment = json_decode((string)$raw, true)['data'] ?? null;
    $paymentStatus = $payment['attributes']['status'] ?? null;

    if ($paymentStatus === 'paid') {
        $paymentReference = trim((string)(
            $payment['attributes']['external_reference_number']
            ?? $payment['attributes']['metadata']['pm_reference_number']
            ?? ''
        ));
        mark_gcash_payment_paid($orderId, $paymentReference);
    } elseif ($paymentStatus === 'failed') {
        mark_gcash_payment_failed($orderId);
    }
}

if ($platform === 'web') {
    // .env should include APP_WEB_URL, e.g.:
    // APP_WEB_URL=https://drews-cafe.vercel.app
    // or APP_WEB_URL=http://localhost:8081 for local Expo web testing.
    $webBaseUrl = rtrim(config('APP_WEB_URL', 'http://localhost:8081'), '/');
    $webUrl = $webBaseUrl . '/order-success?payment=gcash&status=' . urlencode($status) . '&order_id=' . urlencode($orderId);
    header('Location: ' . $webUrl);
    exit;
}

$appUrl = $appScheme . '://payment-result?status=' . $status . '&order_id=' . urlencode($orderId);

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
  <head>
    <meta charset="utf-8" />
    <meta http-equiv="refresh" content="0; url=<?= htmlspecialchars($appUrl, ENT_QUOTES) ?>" />
    <script>window.location.replace(<?= json_encode($appUrl) ?>);</script>
  </head>
  <body style="font-family: sans-serif; text-align: center; padding-top: 3rem;">
    <p>Returning to the app...</p>
    <p><a href="<?= htmlspecialchars($appUrl, ENT_QUOTES) ?>">Tap here if it doesn't open automatically</a></p>
  </body>
</html>
