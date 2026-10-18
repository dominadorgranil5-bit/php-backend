<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_method('GET');
require_role(['staff', 'admin']);

$stmt = db()->prepare(
    'SELECT total FROM orders
     WHERE created_at >= CURDATE() AND status != "cancelled"'
);
$stmt->execute();
$rows = $stmt->fetchAll();

$total = array_reduce($rows, fn($sum, $r) => $sum + (float)$r['total'], 0.0);

json_out(['total' => $total, 'orderCount' => count($rows)]);
