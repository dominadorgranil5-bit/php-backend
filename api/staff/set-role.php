<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_method('POST');
$admin = require_role(['admin']);

$body = json_body();
$id = require_field($body, 'id');
$role = require_field($body, 'role');

if (!in_array($role, ['customer', 'staff', 'admin', 'rider'], true)) {
    json_error('Invalid role.', 400);
}

db()->prepare('UPDATE users SET role = :role WHERE id = :id')
    ->execute(['role' => $role, 'id' => $id]);

// A session is created under the OLD role's rules (single-session flag / expiry), so end the
// account's sessions — it signs in again under the new role. (Not your own, so you aren't kicked out.)
if ($id !== $admin['id']) {
    db()->prepare('DELETE FROM sessions WHERE user_id = :id')->execute(['id' => $id]);
}

json_out(['ok' => true]);
