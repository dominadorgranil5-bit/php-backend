<?php
/** GET — everything the Rider Dashboard needs in one call. */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/rider_helpers.php';
require_method('GET');
$rider = require_role(['rider']);
$rid = ['rid' => $rider['id']];

// Unassigned delivery orders a rider can still claim.
$available = rider_fetch_orders("o.rider_id IS NULL AND o.status IN ('preparing','ready')", [], 'o.created_at ASC');
// Mine, still in progress (waiting for pickup, or on the road).
$active = rider_fetch_orders(
    "o.rider_id = :rid AND o.status IN ('preparing','ready','picked_up','out_for_delivery')", $rid, 'o.created_at ASC'
);
// Mine, finished — delivered or cancelled.
$history = rider_fetch_orders("o.rider_id = :rid AND o.status IN ('delivered','cancelled')", $rid, 'o.updated_at DESC', 50);

$stmt = db()->prepare(
    "SELECT COALESCE(SUM(status IN ('preparing','ready')), 0) AS for_pickup,
            COALESCE(SUM(status IN ('picked_up','out_for_delivery')), 0) AS delivering,
            COALESCE(SUM(status = 'delivered'), 0) AS completed,
            COALESCE(SUM(status = 'delivered' AND DATE(delivered_at) = CURDATE()), 0) AS today
     FROM orders WHERE rider_id = :rid"
);
$stmt->execute($rid);
$s = $stmt->fetch();

json_out([
    'available' => $available,
    'active' => $active,
    'history' => $history,
    'stats' => [
        'for_pickup' => (int)$s['for_pickup'],
        'delivering' => (int)$s['delivering'],
        'completed' => (int)$s['completed'],
        'today' => (int)$s['today'],
    ],
]);
