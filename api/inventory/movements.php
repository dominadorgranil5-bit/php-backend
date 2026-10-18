<?php
/** GET [?ingredient_id=] — latest 100 stock movements. staff/admin. */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_method('GET');
require_role(['staff', 'admin']);

$ingredientId = trim((string)($_GET['ingredient_id'] ?? ''));
$sql = 'SELECT m.id, m.order_id, m.change_qty, m.reason, m.note, m.created_at,
               i.name AS ingredient_name, i.unit, u.name AS user_name
        FROM inventory_movements m
        JOIN ingredients i ON i.id = m.ingredient_id
        LEFT JOIN users u ON u.id = m.created_by'
     . ($ingredientId !== '' ? ' WHERE m.ingredient_id = :iid' : '')
     . ' ORDER BY m.id DESC LIMIT 100';
$stmt = db()->prepare($sql);
$stmt->execute($ingredientId !== '' ? ['iid' => $ingredientId] : []);

$rows = $stmt->fetchAll();
foreach ($rows as &$r) {
    $r['change_qty'] = (float)$r['change_qty'];
}
unset($r);
json_out($rows);
