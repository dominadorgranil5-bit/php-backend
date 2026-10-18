<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/orders_helpers.php';
require_method('GET');
require_role(['staff', 'admin']);

$startParam = trim((string)($_GET['start'] ?? ''));
$endParam = trim((string)($_GET['end'] ?? ''));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startParam) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endParam)) {
    json_error('start and end query parameters are required as YYYY-MM-DD.', 400);
}

try {
    $start = new DateTime($startParam . ' 00:00:00');
    $end = new DateTime($endParam . ' 23:59:59'); // inclusive of the whole end day
} catch (Exception $e) {
    json_error('Invalid date.', 400);
}

if ($start > $end) {
    json_error('start date must be on or before the end date.', 400);
}

// Guard against absurdly large ranges (daily rows + order detail are returned).
if ($start->diff($end)->days > 366) {
    json_error('Date range is too large. Please pick 366 days or fewer.', 400);
}

// all_items=1 (closing report): return EVERY product sold, not just the top 10.
$topLimit = ($_GET['all_items'] ?? '') === '1' ? 1000 : 10;
$breakdown = build_sales_breakdown($start, $end, $topLimit);

json_out([
    'start' => $startParam,
    'end' => $endParam,
    'generatedAt' => date('Y-m-d H:i:s'),
    'total' => $breakdown['total'],
    'orderCount' => $breakdown['orderCount'],
    'unitsSold' => $breakdown['unitsSold'],
    'aov' => $breakdown['aov'],
    'topItems' => $breakdown['topItems'],
    'daily' => $breakdown['daily'],
    'orders' => $breakdown['orders'],
]);
