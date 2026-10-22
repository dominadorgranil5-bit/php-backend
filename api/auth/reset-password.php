<?php
/** Password recovery step 2: code + new password. Signs the account out everywhere. */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/otp.php';
require_once __DIR__ . '/../../lib/password_validation.php';
require_method('POST');

$body = json_body();
$email = strtolower(trim(require_field($body, 'email')));
$code = preg_replace('/\D/', '', require_field($body, 'code'));
$newPassword = require_field($body, 'new_password');

require_strong_password($newPassword); // customers: length + uppercase + lowercase + number

$row = otp_verify($email, 'reset', $code);

$pdo = db();
$stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email AND role = "customer"');
$stmt->execute(['email' => $email]);
$userId = $stmt->fetchColumn();
if (!$userId) {
    json_error('This code has expired or was not requested. Please request a new one.', 400);
}

otp_consume($row['id']);

$pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')
    ->execute(['hash' => password_hash($newPassword, PASSWORD_BCRYPT), 'id' => $userId]);

// Old sessions (possibly a thief's) are no longer valid after a password reset.
$pdo->prepare('DELETE FROM sessions WHERE user_id = :id')->execute(['id' => $userId]);

json_out(['ok' => true]);
