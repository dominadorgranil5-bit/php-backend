<?php
require_once __DIR__ . '/inventory_helpers.php';
/**
 * lib/payment_notifications.php — customer and merchant notifications for
 * GCash/PayMongo payment outcomes.
 *
 * The order was already inserted by api/orders/place.php before checkout
 * started (PayMongo needs an order_id to redirect back to), so we can't
 * stop it existing in the table. What we *can* do is mark it
 * 'payment_failed' so it never surfaces as a real order to fulfill:
 *   - api/orders/all.php (staff/admin order list) filters it out.
 *   - api/orders/stream.php's staff/admin "something changed" fingerprint
 *     filters it out too, so it doesn't even trigger a refresh/notification.
 *   - lib/orders_helpers.php's sales report excludes it from revenue.
 * The customer's own order history (api/orders/customer.php) is NOT
 * filtered, so they can still see it there, and we also email them
 * directly so they don't have to go looking.
 *
 * Safe to call more than once for the same order (webhook.php, the
 * payment-redirect.php bridge page, and PayMongo's redirect can all notice
 * the same failure) — the UPDATE only ever fires once, so the customer
 * only gets a single email.
 */

require_once __DIR__ . '/mailer.php';

function mark_gcash_payment_paid(string $orderId, string $paymentReference): void
{
    if ($orderId === '') {
        return;
    }

    $pdo = db();
    $justPaid = false;
    $order = null;

    // 1) Mark the order PAID first. This must never depend on email/SMTP:
    //    once PayMongo says the money is in, the order is paid, period.
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'SELECT total, customer_id, payment_method, payment_status, status, payment_reference
             FROM orders WHERE id = :id FOR UPDATE'
        );
        $stmt->execute(['id' => $orderId]);
        $order = $stmt->fetch();

        if (!$order || $order['payment_method'] !== 'gcash' || $order['payment_status'] === 'paid') {
            $pdo->rollBack();
            return;
        }

        // PayMongo doesn't always return a customer reference number (QR Ph
        // often doesn't). Fall back to what we already stored (the intent /
        // source id) so the order still gets a reference and still gets paid.
        if ($paymentReference === '') {
            $paymentReference = trim((string)($order['payment_reference'] ?? ''));
        }

        $update = $pdo->prepare(
            'UPDATE orders SET payment_status = "paid", payment_reference = :reference,
                status = IF(status = "payment_failed", "pending", status)
             WHERE id = :id AND payment_method = "gcash" AND payment_status = "unpaid"'
        );
        $update->execute(['reference' => $paymentReference, 'id' => $orderId]);
        $justPaid = $update->rowCount() > 0;
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[payment_notifications] failed to mark order ' . $orderId . ' paid: ' . $e->getMessage());
        throw $e;
    }

    if (!$justPaid) {
        return;
    }

    // A failed-then-paid order had its stock returned; take it out again (no-op if still deducted).
    deduct_inventory_at_checkout($orderId);

    // 2) Receipt email is best-effort only — a broken SMTP setup must not
    //    undo (or block) a payment that already went through.
    try {
        $customerStmt = $pdo->prepare('SELECT name, email FROM users WHERE id = :id');
        $customerStmt->execute(['id' => $order['customer_id']]);
        $customer = $customerStmt->fetch() ?: [];

        $recipient = trim((string)config('PAYMENT_NOTIFICATION_EMAIL', ''));
        if ($recipient === '') {
            $recipient = trim((string)config('MAIL_FROM', ''));
        }
        if ($recipient === '') {
            $recipient = trim((string)config('SMTP_USER', ''));
        }
        if ($recipient === '') {
            throw new RuntimeException('Receipt email recipient is not configured.');
        }

        $itemsStmt = $pdo->prepare(
            'SELECT name, quantity, price FROM order_items WHERE order_id = :id ORDER BY id'
        );
        $itemsStmt->execute(['id' => $orderId]);
        $items = $itemsStmt->fetchAll();

        send_gcash_paid_email(
            $recipient,
            $orderId,
            $paymentReference,
            (string)($customer['name'] ?? ''),
            (string)($customer['email'] ?? ''),
            (float)$order['total'],
            $items
        );
    } catch (Throwable $e) {
        error_log('[payment_notifications] order ' . $orderId . ' is paid but the receipt email failed: ' . $e->getMessage());
    }
}

/**
 * QR Ph has no browser redirect (unlike the GCash-app flow), so when the
 * PayMongo webhook can't reach this backend (local XAMPP / no ngrok) the
 * order would stay "unpaid" forever. This asks PayMongo directly whether the
 * order's latest Payment Intent has succeeded and, if so, marks the order
 * paid. Called from api/orders/status.php, which the QR modal polls every 3s.
 * Safe to call repeatedly — mark_gcash_payment_paid() only ever flips once.
 */
function sync_qrph_payment_if_needed(string $orderId): void
{
    require_once __DIR__ . '/paymongo.php';

    $stmt = db()->prepare(
        'SELECT payment_method, payment_status, paymongo_payment_intent_id
         FROM orders WHERE id = :id'
    );
    $stmt->execute(['id' => $orderId]);
    $order = $stmt->fetch();

    if (
        !$order
        || $order['payment_method'] !== 'gcash'
        || $order['payment_status'] === 'paid'
        || empty($order['paymongo_payment_intent_id'])
    ) {
        return;
    }

    $intentId = (string)$order['paymongo_payment_intent_id'];
    [$httpStatus, $data, $err] = paymongo_get("/payment_intents/$intentId", (string)config('PAYMONGO_SECRET_KEY', ''));
    if ($err !== null || !$data || $httpStatus < 200 || $httpStatus >= 300) {
        return; // transient — the next poll tries again
    }

    $attrs = $data['data']['attributes'] ?? [];
    if (($attrs['status'] ?? '') !== 'succeeded') {
        return; // still awaiting_payment_method / awaiting_next_action / processing
    }

    $payment = $attrs['payments'][0] ?? [];
    $reference = trim((string)(
        $payment['attributes']['external_reference_number']
        ?? $payment['attributes']['metadata']['pm_reference_number']
        ?? $payment['id']
        ?? ''
    ));
    mark_gcash_payment_paid($orderId, $reference);
}

function send_gcash_paid_email(
    string $email,
    string $orderId,
    string $paymentReference,
    string $customerName,
    string $customerEmail,
    float $total,
    array $items
): void {
    $app = htmlspecialchars((string)config('APP_NAME', "Drew's Cafe"), ENT_QUOTES, 'UTF-8');
    $shortId = strtoupper(substr($orderId, 0, 8));
    $safeReference = htmlspecialchars($paymentReference, ENT_QUOTES, 'UTF-8');
    $safeCustomerName = htmlspecialchars($customerName !== '' ? $customerName : 'Not provided', ENT_QUOTES, 'UTF-8');
    $safeCustomerEmail = htmlspecialchars($customerEmail !== '' ? $customerEmail : 'Not provided', ENT_QUOTES, 'UTF-8');
    $amount = number_format($total, 2);
    $itemRows = '';
    $textItems = [];

    foreach ($items as $item) {
        $name = (string)($item['name'] ?? '');
        $quantity = (int)($item['quantity'] ?? 0);
        $lineTotal = number_format((float)($item['price'] ?? 0) * $quantity, 2);
        $itemRows .= '<tr><td style="padding:6px 12px 6px 0;">'
            . htmlspecialchars($name, ENT_QUOTES, 'UTF-8')
            . '</td><td style="padding:6px 12px;">' . $quantity
            . '</td><td style="padding:6px 0;text-align:right;">&#8369;' . $lineTotal . '</td></tr>';
        $textItems[] = "$quantity x $name - PHP $lineTotal";
    }

    $subject = "GCash payment received - Order #$shortId";
    $html = <<<HTML
<div style="font-family:Arial,Helvetica,sans-serif;color:#3D3028;padding:20px;">
  <h2 style="margin:0 0 16px;">{$app}: GCash payment received</h2>
  <p style="margin:0 0 6px;"><strong>Order:</strong> #{$shortId}</p>
  <p style="margin:0 0 6px;"><strong>PayMongo reference:</strong> {$safeReference}</p>
  <p style="margin:0 0 6px;"><strong>Customer:</strong> {$safeCustomerName} ({$safeCustomerEmail})</p>
  <table style="border-collapse:collapse;margin:16px 0;width:100%;">
    <thead><tr><th style="text-align:left;padding:6px 12px 6px 0;">Item</th><th style="text-align:left;padding:6px 12px;">Qty</th><th style="text-align:right;padding:6px 0;">Amount</th></tr></thead>
    <tbody>{$itemRows}</tbody>
  </table>
  <p style="margin:0;"><strong>Total paid:</strong> &#8369;{$amount}</p>
</div>
HTML;

    $text = "$app: GCash payment received\n"
        . "Order: #$shortId\nPayMongo reference: $paymentReference\n"
        . "Customer: " . ($customerName !== '' ? $customerName : 'Not provided') . ' ('
        . ($customerEmail !== '' ? $customerEmail : 'Not provided') . ")\n\n"
        . "Items:\n" . implode("\n", $textItems) . "\n\nTotal paid: PHP $amount";

    send_mail($email, $subject, $html, $text);
}

function mark_gcash_payment_failed(string $orderId): void
{
    if ($orderId === '') {
        return;
    }

    $pdo = db();

    // Only flip orders that are still unpaid and not already marked
    // failed. This is also the guard that stops a duplicate email when
    // more than one PayMongo signal reports the same failure.
    $stmt = $pdo->prepare(
        "UPDATE orders SET status = 'payment_failed'
         WHERE id = :id AND payment_status = 'unpaid' AND status != 'payment_failed'"
    );
    $stmt->execute(['id' => $orderId]);

    if ($stmt->rowCount() === 0) {
        return; // already paid, already marked failed, or no such order
    }

    restore_inventory_for_order($orderId); // the sale never happened - give the stock back

    $stmt = $pdo->prepare(
        'SELECT o.total, u.email, u.name
         FROM orders o
         JOIN users u ON u.id = o.customer_id
         WHERE o.id = :id'
    );
    $stmt->execute(['id' => $orderId]);
    $row = $stmt->fetch();

    if (!$row || !$row['email']) {
        return; // nothing to email
    }

    try {
        send_gcash_failed_email($row['email'], (string)($row['name'] ?? ''), $orderId, (float)$row['total']);
    } catch (Throwable $e) {
        // Never let a broken SMTP config re-throw into a webhook/redirect
        // response — the order is already correctly marked failed.
        error_log('[payment_notifications] failed to email customer for order ' . $orderId . ': ' . $e->getMessage());
    }
}

function send_gcash_failed_email(string $email, string $name, string $orderId, float $total): void
{
    $app = (string)config('APP_NAME', "Drew's Cafe");
    $safeApp = htmlspecialchars($app, ENT_QUOTES, 'UTF-8');
    $hello = $name !== '' ? 'Hi ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ',' : 'Hi,';
    $helloText = $name !== '' ? "Hi $name," : 'Hi,';
    $shortId = strtoupper(substr($orderId, 0, 8));
    $amount = number_format($total, 2);
    $subject = 'Your GCash payment was unsuccessful';

    $html = <<<HTML
<div style="font-family:Arial,Helvetica,sans-serif;background:#F8F5F0;padding:24px;">
  <div style="max-width:420px;margin:0 auto;background:#ffffff;border-radius:16px;padding:28px;color:#3D3028;">
    <h2 style="margin:0 0 12px;color:#6B4226;">{$safeApp}</h2>
    <p style="margin:0 0 8px;">{$hello}</p>
    <p style="margin:0 0 16px;">Your GCash payment for order #{$shortId} (&#8369;{$amount}) was not successful, so this order was not placed.</p>
    <p style="margin:0 0 16px;">You can go back to the app and try again. No amount was charged to your GCash account.</p>
  </div>
</div>
HTML;

    $text = "$helloText\n\n"
        . "Your GCash payment for order #$shortId (P$amount) was not successful, so this order was not placed.\n\n"
        . "You can go back to the app and try again. No amount was charged to your GCash account.";

    send_mail($email, $subject, $html, $text);
}
