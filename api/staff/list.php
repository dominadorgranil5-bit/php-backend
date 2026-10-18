<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_method('GET');
require_role(['admin']);

$stmt = db()->query(
    "SELECT id, name, email, phone, role, is_active FROM users
     WHERE role IN ('staff', 'admin', 'rider') ORDER BY role ASC"
);
$rows = $stmt->fetchAll();
foreach ($rows as &$row) {
    $row['is_active'] = (bool)$row['is_active'];
}
json_out($rows);
