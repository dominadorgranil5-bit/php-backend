<?php
/** GET — admin-only. Latest notifications + unread count (for the dashboard bell). */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_method('GET');
require_role(['admin']);

$pdo = db();
$rows = $pdo->query(
    'SELECT id, type, account_email, account_name, account_role, result, ip_address, is_read, created_at
     FROM admin_notifications
     ORDER BY created_at DESC, id DESC
     LIMIT 50'
)->fetchAll();

foreach ($rows as &$r) {
    $r['id'] = (int)$r['id'];
    $r['is_read'] = (int)$r['is_read'] === 1;
}
unset($r);

$unread = (int)$pdo->query('SELECT COUNT(*) FROM admin_notifications WHERE is_read = 0')->fetchColumn();

json_out(['unread_count' => $unread, 'notifications' => $rows]);
