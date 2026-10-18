<?php
/**
 * Diagnostic lang (walang binabago sa database): bakit walang nakikitang delivery ang Rider?
 * Patakbuhin:  C:\xampp\php\php.exe diagnose-rider.php   (o buksan sa browser)
 * Burahin ang file na ito pagkatapos.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';
header('Content-Type: text/plain; charset=utf-8');

$pdo = db();
echo "== 1. Columns/enums na kailangan ng Rider App ==\n";
foreach (['rider_id', 'delivery_fee', 'distance_km', 'rider_accepted_at', 'picked_up_at', 'delivered_at'] as $c) {
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name='orders' AND column_name = :c");
    $s->execute(['c' => $c]);
    echo str_pad("orders.$c", 26) . ($s->fetchColumn() ? 'OK' : "WALA  -> patakbuhin ang migrate-rider.php") . "\n";
}
$t = $pdo->query("SELECT COLUMN_TYPE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name='orders' AND column_name='status'")->fetchColumn();
echo "orders.status = $t\n";
echo str_contains((string)$t, 'picked_up') ? "OK (may picked_up/delivered)\n" : "WALA ang rider statuses -> patakbuhin ang migrate-rider.php\n";
$r = $pdo->query("SELECT COLUMN_TYPE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name='users' AND column_name='role'")->fetchColumn();
echo "users.role = $r\n\n";

echo "== 2. Rider accounts ==\n";
foreach ($pdo->query("SELECT email, is_active FROM users WHERE role='rider'") as $u) {
    echo "{$u['email']}  " . ($u['is_active'] ? 'active' : 'DISABLED') . "\n";
}
echo "\n== 3. Delivery orders (bilang kada status / payment / may rider ba) ==\n";
$rows = $pdo->query(
    "SELECT status, payment_method, payment_status, (rider_id IS NOT NULL) AS may_rider, COUNT(*) n
     FROM orders WHERE order_type='delivery' GROUP BY 1,2,3,4 ORDER BY 1"
)->fetchAll();
if (!$rows) echo "Walang delivery order sa database.\n";
foreach ($rows as $x) {
    echo str_pad($x['status'], 18) . str_pad($x['payment_method'], 7) . str_pad($x['payment_status'], 8)
       . ($x['may_rider'] ? 'may rider ' : 'walang rider ') . "x{$x['n']}\n";
}

echo "\n== 4. Ilan ang makikita ng Rider sa 'Available' ==\n";
$n = $pdo->query(
    "SELECT COUNT(*) FROM orders o JOIN users u ON u.id = o.customer_id
     WHERE o.order_type='delivery' AND o.status != 'payment_failed'
       AND (o.payment_method != 'gcash' OR o.payment_status='paid')
       AND o.rider_id IS NULL AND o.status IN ('preparing','ready')"
)->fetchColumn();
echo "$n order(s)\n";
echo "\nPaalala: lalabas lang sa Rider ang delivery order kapag 'preparing' o 'ready' na (i-Accept muna ng staff),\n"
   . "walang rider pa, at kung GCash ay bayad na.\n";
