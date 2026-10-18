<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_method('POST');
$admin = require_role(['admin']);

$body = json_body();
$id = require_field($body, 'id');
$active = $body['active'] ?? null;

if (!is_bool($active)) {
    json_error('active must be true or false.', 400);
}

if ($id === $admin['id']) {
    json_error('You cannot disable your own account.', 400);
}

$pdo = db();

$stmt = $pdo->prepare('SELECT id, role FROM users WHERE id = :id');
$stmt->execute(['id' => $id]);
$target = $stmt->fetch();
if (!$target) {
    json_error('Account not found.', 404);
}

$pdo->prepare('UPDATE users SET is_active = :active WHERE id = :id')
    ->execute(['active' => $active ? 1 : 0, 'id' => $id]);

// Disabling should take effect immediately, not just on next login —
// drop any sessions this account currently holds so they're signed out now.
if (!$active) {
    $pdo->prepare('DELETE FROM sessions WHERE user_id = :id')->execute(['id' => $id]);
}

json_out(['ok' => true]);
