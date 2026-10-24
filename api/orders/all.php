<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/orders_helpers.php';
require_method('GET');
require_role(['staff', 'admin']);

// payment_failed orders (GCash payments that PayMongo never confirmed) are
// deliberately excluded — they never became real orders, so staff/admin
// should never see them. See lib/payment_notifications.php.
//
// The rider is LEFT JOINed so staff can see WHO took a delivery (rider_name).
// Account columns are aliased account_* — o.* now carries the order's own
// customer_name / contact_number (POS-captured), and a colliding alias would
// silently overwrite them in the fetched row.
$stmt = db()->query(
    'SELECT o.*, u.name AS account_name, u.email AS account_email, u.phone AS account_phone,
            r.name AS rider_name
     FROM orders o
     JOIN users u ON u.id = o.customer_id
     LEFT JOIN users r ON r.id = o.rider_id
         WHERE o.status != "payment_failed"
             AND (o.payment_method != "gcash" OR o.payment_status = "paid")
     ORDER BY o.created_at DESC'
);
$rows = $stmt->fetchAll();

foreach ($rows as &$row) {
    // profiles = the CUSTOMER as staff should see them: for POS orders that's the
    // walk-in customer recorded at the counter, not the staff account that rang it up.
    $row['profiles'] = [
        'name' => ($row['customer_name'] ?? '') !== '' ? $row['customer_name'] : $row['account_name'],
        'email' => $row['account_email'],
        'phone' => ($row['contact_number'] ?? '') !== '' ? $row['contact_number'] : $row['account_phone'],
    ];
    $row['rider_name'] = $row['rider_name'] ?: null;
    unset($row['account_name'], $row['account_email'], $row['account_phone']);
}
unset($row);

json_out(attach_order_items($rows));
