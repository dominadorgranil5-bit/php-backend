<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/orders_helpers.php';
require_method('GET');
require_role(['staff', 'admin']);

// payment_failed orders (GCash payments that PayMongo never confirmed) are
// deliberately excluded — they never became real orders, so staff/admin
// should never see them. See lib/payment_notifications.php.
$stmt = db()->query(
    'SELECT o.*, u.name AS customer_name, u.email AS customer_email, u.phone AS customer_phone
     FROM orders o
     JOIN users u ON u.id = o.customer_id
         WHERE o.status != "payment_failed"
             AND (o.payment_method != "gcash" OR o.payment_status = "paid")
     ORDER BY o.created_at DESC'
);
$rows = $stmt->fetchAll();

foreach ($rows as &$row) {
    $row['profiles'] = [
        'name' => $row['customer_name'],
        'email' => $row['customer_email'],
        'phone' => $row['customer_phone'],
    ];
    unset($row['customer_name'], $row['customer_email'], $row['customer_phone']);
}
unset($row);

json_out(attach_order_items($rows));
