<?php
/**
 * POST {new_password} — the forced "choose your own password" step for staff/riders whose password
 * is a temporary one from the admin (users.must_change_password = 1). Only works while that flag is set,
 * so it can't be used to change a password without knowing the current one. The temporary password
 * itself cannot be reused. Other sessions of this account are ended; the current one stays signed in.
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/password_validation.php';
require_method('POST');

$user = require_user();
if (empty($user['must_change_password'])) {
    json_error('Your password does not need to be changed right now.', 400);
}

$newPassword = require_field(json_body(), 'new_password');
// Riders chose their own password here, so they get uppercase + lowercase + number;
// staff keep the simple length-only rule.
if (($user['role'] ?? '') === 'staff') {
    require_valid_password($newPassword);
} else {
    require_strong_password($newPassword);
}

$pdo = db();
$stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
$stmt->execute(['id' => $user['id']]);
if (password_verify($newPassword, (string)$stmt->fetchColumn())) {
    json_error('Please choose a different password from the temporary one.', 400);
}

$pdo->prepare('UPDATE users SET password_hash = :hash, must_change_password = 0 WHERE id = :id')
    ->execute(['hash' => password_hash($newPassword, PASSWORD_BCRYPT), 'id' => $user['id']]);

// End every OTHER session of this account (keep the one making this request).
$token = bearer_token();
if ($token) {
    $pdo->prepare('DELETE FROM sessions WHERE user_id = :id AND token <> :token')
        ->execute(['id' => $user['id'], 'token' => hash_session_token($token)]);
}

json_out(['ok' => true]);
