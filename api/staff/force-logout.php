<?php
/**
 * POST {id} — admin-only. Ends a STAFF member's active session so they can log in again.
 * Staff sessions last 12 hours, so this releases one immediately if someone forgot to log out
 * (closed the browser, device died, ...). A rejected duplicate login never does this by itself.
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_method('POST');
require_role(['admin']);

$id = require_field(json_body(), 'id');

$pdo = db();
$stmt = $pdo->prepare('SELECT id, role FROM users WHERE id = :id');
$stmt->execute(['id' => $id]);
$target = $stmt->fetch();
if (!$target) {
    json_error('Account not found.', 404);
}
if ($target['role'] !== 'staff') {
    json_error('Only staff sessions can be ended this way.', 400);
}

$pdo->prepare('DELETE FROM sessions WHERE user_id = :id')->execute(['id' => $id]);

json_out(['ok' => true]);
