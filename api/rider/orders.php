<?php
/** GET — everything the Rider Dashboard needs in one call. */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/rider_helpers.php';
require_method('GET');
$rider = require_role(['rider']);
$rid = ['rid' => $rider['id']];

// Unassigned delivery orders a rider can still claim — a rider may only claim an order
// once staff has marked it Ready to Pick Up (never straight out of the kitchen).
$available = rider_fetch_orders("o.rider_id IS NULL AND o.status = 'ready'", [], 'o.created_at ASC');
// Mine, still in progress: accepted and waiting to leave, or already on the road.
// ('ready' is only left over from riders who accepted before that rule; accepting now
//  moves the order straight to picked_up.)
$active = rider_fetch_orders(
    "o.rider_id = :rid AND o.status IN ('ready','picked_up','out_for_delivery')", $rid, 'o.created_at ASC'
);
// Mine, finished — delivered or cancelled.
$history = rider_fetch_orders("o.rider_id = :rid AND o.status IN ('delivered','cancelled')", $rid, 'o.updated_at DESC', 50);

$stmt = db()->prepare(
    "SELECT COALESCE(SUM(status IN ('ready','picked_up')), 0) AS to_deliver,
            COALESCE(SUM(status = 'out_for_delivery'), 0) AS delivering,
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
        'to_deliver' => (int)$s['to_deliver'],
        'delivering' => (int)$s['delivering'],
        'completed' => (int)$s['completed'],
        'today' => (int)$s['today'],
    ],
]);
