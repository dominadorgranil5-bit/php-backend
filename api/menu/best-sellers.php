<?php
/**
 * GET /api/menu/best-sellers.php
 *
 * Public (for customers) — returns the best seller of EVERY category that has sales.
 *
 * Counted the same way as the sales reports (build_sales_breakdown): cancelled / payment_failed
 * orders are ignored, and GCash orders only count once PayMongo marked them paid.
 * Only items that are still on the menu AND available are eligible.
 *
 * Optional query params:
 *   days=30   how far back to look (1-365, default 30)
 *   top=1     how many best sellers per category (1-3, default 1)
 *
 * Response: [ { id, name, category, sold, rank }, ... ]  (rank 1 = best seller of that category)
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_method('GET');

// Minimum units sold before an item can be called a "best seller" (1 = any item that sold).
const BEST_SELLER_MIN_SOLD = 1;

$days = max(1, min(365, (int)($_GET['days'] ?? 30)));
$top = max(1, min(3, (int)($_GET['top'] ?? 1)));

$since = (new DateTime())->modify("-{$days} days")->setTime(0, 0, 0)->format('Y-m-d H:i:s');

$stmt = db()->prepare(
    'SELECT mi.id, mi.name, mi.category, SUM(oi.quantity) AS sold
     FROM order_items oi
     JOIN orders o      ON o.id = oi.order_id
     JOIN menu_items mi ON mi.id = oi.product_id
     WHERE mi.available = 1
       AND o.status NOT IN ("cancelled", "payment_failed")
       AND (o.payment_method != "gcash" OR o.payment_status = "paid")
       AND o.created_at >= :since
     GROUP BY mi.id, mi.name, mi.category
     HAVING sold >= :min_sold
     ORDER BY mi.category ASC, sold DESC, mi.name ASC'
);
$stmt->execute(['since' => $since, 'min_sold' => BEST_SELLER_MIN_SOLD]);

$out = [];
$rankByCategory = [];
foreach ($stmt->fetchAll() as $row) {
    $cat = $row['category'];
    $rankByCategory[$cat] = ($rankByCategory[$cat] ?? 0) + 1;
    if ($rankByCategory[$cat] > $top) {
        continue; // already have enough for this category
    }
    $out[] = [
        'id' => $row['id'],
        'name' => $row['name'],
        'category' => $cat,
        'sold' => (int)$row['sold'],
        'rank' => $rankByCategory[$cat],
    ];
}

json_out($out);
