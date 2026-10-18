<?php
/** POST {id} or {all: true} — admin-only. Marks notification(s) as read. */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_method('POST');
require_role(['admin']);

$body = json_body();
$pdo = db();

if (($body['all'] ?? false) === true) {
    $pdo->exec('UPDATE admin_notifications SET is_read = 1, read_at = NOW() WHERE is_read = 0');
    json_out(['ok' => true]);
}

$id = (string)($body['id'] ?? '');
if ($id === '' || !ctype_digit($id)) {
    json_error('id is required.', 400);
}

$pdo->prepare('UPDATE admin_notifications SET is_read = 1, read_at = NOW() WHERE id = :id AND is_read = 0')
    ->execute(['id' => (int)$id]);

json_out(['ok' => true]);
