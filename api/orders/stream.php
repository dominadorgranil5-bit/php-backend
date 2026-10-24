<?php
/**
 * api/orders/stream.php — live "something changed" signal for order screens,
 * via Server-Sent Events. It does NOT ship order data itself; the frontend
 * refetches its order list when it hears the event, same as manual refresh.
 *
 * Auth: EventSource can't set custom headers, so the frontend passes the
 * bearer token as ?token=... (see bearer_token() in lib/auth.php).
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_method('GET');
$user = require_role(['customer', 'staff', 'admin', 'rider']);
$customerId = $user['role'] === 'customer' ? $user['id'] : null;
$riderId = $user['role'] === 'rider' ? $user['id'] : null;

set_time_limit(0);
ignore_user_abort(true);

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache, no-transform');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // in case this ever sits behind nginx/a proxy

while (ob_get_level() > 0) {
    ob_end_flush();
}

/**
 * Cheap fingerprint of the orders table: the row count changes on every new
 * order, and updated_at changes on every status/payment update — together
 * they catch every kind of change without shipping the whole order list.
 */
function orders_fingerprint(?string $customerId = null, ?string $riderId = null): string
{
    if ($riderId !== null) {
        // Rider: delivery orders still claimable (Ready to Pick Up, no rider yet) plus their own,
        // so a new Ready order or a staff cancel shows up on the rider's screen at once.
        $stmt = db()->prepare(
            "SELECT id, status, rider_id, updated_at FROM orders
             WHERE order_type = 'delivery' AND status != 'payment_failed'
               AND (payment_method != 'gcash' OR payment_status = 'paid')
               AND ((rider_id IS NULL AND status IN ('ready','cancelled')) OR rider_id = :rid)
             ORDER BY id"
        );
        $stmt->execute(['rid' => $riderId]);
        return hash('sha256', serialize($stmt->fetchAll()));
    }

    if ($customerId !== null) {
        $stmt = db()->prepare(
            'SELECT id, status, payment_status, updated_at
             FROM orders WHERE customer_id = :customer_id ORDER BY id'
        );
        $stmt->execute(['customer_id' => $customerId]);
        return hash('sha256', serialize($stmt->fetchAll()));
    }

        // Only settled GCash orders reach staff, matching api/orders/all.php.
    $row = db()->query(
        'SELECT COUNT(*) AS cnt, COALESCE(MAX(updated_at), "") AS latest,
                COALESCE(SUM(CRC32(CONCAT(id, status, payment_status, updated_at))), 0) AS sig
                 FROM orders
                 WHERE status != "payment_failed"
                     AND (payment_method != "gcash" OR payment_status = "paid")'
    )->fetch();
    return $row['cnt'] . ':' . $row['latest'] . ':' . $row['sig'];
}

$last = orders_fingerprint($customerId, $riderId); // don't fire immediately on connect, only on real changes
$start = time();

// Each connection stays open ~55s then closes cleanly; the browser's
// EventSource reconnects a moment later on its own. This keeps a single
// tab from holding an Apache/PHP worker open forever.
while (time() - $start < 55) {
    if (connection_aborted()) {
        break;
    }

    $fingerprint = orders_fingerprint($customerId, $riderId);
    if ($fingerprint !== $last) {
        $last = $fingerprint;
        echo "event: orders-changed\n";
        echo 'data: ' . json_encode(['at' => date('c')]) . "\n\n";
    } else {
        echo ": keep-alive\n\n"; // comment line, ignored by EventSource, just keeps the connection open
    }

    @flush();
    sleep(1);
}
