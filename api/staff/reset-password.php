<?php
/**
 * POST {id, password?} — admin-only. For a staff/rider who forgot their password: sets a NEW TEMPORARY
 * password (the one in `password`, or a random one when it's left out), makes them choose their own on
 * next sign-in, and signs them out everywhere. The temporary password is returned ONCE so the admin can
 * hand it over — it is not stored in readable form.
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/password_validation.php';
require_method('POST');
require_role(['admin']);

$body = json_body();
$id = require_field($body, 'id');

$pdo = db();
$stmt = $pdo->prepare('SELECT id, role FROM users WHERE id = :id');
$stmt->execute(['id' => $id]);
$target = $stmt->fetch();
if (!$target) {
    json_error('Account not found.', 404);
}
if (!in_array($target['role'], ['staff', 'rider'], true)) {
    json_error('Temporary passwords can only be set for staff and rider accounts.', 400);
}

$password = trim((string)($body['password'] ?? ''));
if ($password === '') {
    // 10 chars, no look-alike characters (0/O, 1/l/I) so it is easy to read out or type.
    $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $password = '';
    for ($i = 0; $i < 10; $i++) {
        $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
} else {
    require_valid_password($password);
}

$pdo->prepare('UPDATE users SET password_hash = :hash, must_change_password = 1 WHERE id = :id')
    ->execute(['hash' => password_hash($password, PASSWORD_BCRYPT), 'id' => $id]);
$pdo->prepare('DELETE FROM sessions WHERE user_id = :id')->execute(['id' => $id]);

json_out(['ok' => true, 'temporary_password' => $password]);
