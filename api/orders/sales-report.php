<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/orders_helpers.php';
require_method('GET');
require_role(['staff', 'admin']);

$now = new DateTime();
$startOfDay = (clone $now)->setTime(0, 0, 0);
$startOfWeek = (clone $startOfDay)->modify('-' . (int)$startOfDay->format('w') . ' days');
$startOfMonth = (clone $now)->modify('first day of this month')->setTime(0, 0, 0);

// Today / this week / this month revenue totals.
$stmt = db()->prepare(
    'SELECT total, created_at FROM orders
     WHERE status != "cancelled" AND created_at >= :start_of_month'
);
$stmt->execute(['start_of_month' => $startOfMonth->format('Y-m-d H:i:s')]);
$monthOrders = $stmt->fetchAll();

$sumSince = function (DateTime $from) use ($monthOrders): float {
    $sum = 0.0;
    foreach ($monthOrders as $row) {
        if (new DateTime($row['created_at']) >= $from) {
            $sum += (float)$row['total'];
        }
    }
    return $sum;
};

// Best sellers, scoped to this month.
$breakdown = build_sales_breakdown($startOfMonth, $now);

json_out([
    'today' => $sumSince($startOfDay),
    'thisWeek' => $sumSince($startOfWeek),
    'thisMonth' => $sumSince($startOfMonth),
    'topItems' => $breakdown['topItems'],
]);
