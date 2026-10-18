<?php
/** lib/rider_helpers.php — shared by api/rider/*.php */
require_once __DIR__ . '/orders_helpers.php';

/** Delivery orders a rider may ever see: real (not payment_failed, GCash must be paid). */
const RIDER_BASE_WHERE =
    "o.order_type = 'delivery' AND o.status != 'payment_failed'
     AND (o.payment_method != 'gcash' OR o.payment_status = 'paid')";

/** Fetches orders (+ customer name/phone + line items) in the shape the Rider App expects. */
function rider_fetch_orders(string $where, array $params, string $orderBy, int $limit = 100): array
{
    $stmt = db()->prepare(
        "SELECT o.*, u.name AS customer_name, u.phone AS customer_phone
         FROM orders o JOIN users u ON u.id = o.customer_id
         WHERE " . RIDER_BASE_WHERE . " AND $where
         ORDER BY $orderBy LIMIT $limit"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$r) {
        $r['total'] = (float)$r['total'];
        $r['delivery_fee'] = $r['delivery_fee'] !== null ? (float)$r['delivery_fee'] : null;
        $r['distance_km'] = $r['distance_km'] !== null ? (float)$r['distance_km'] : null;
        $r['profiles'] = ['name' => $r['customer_name'], 'phone' => $r['customer_phone']];
        unset($r['customer_name'], $r['customer_phone']);
    }
    unset($r);

    return attach_order_items($rows);
}
