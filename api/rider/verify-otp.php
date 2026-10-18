<?php
/** POST {email, code} — step 2 of rider sign-in. The code only exists after auth/login.php accepted the password. Returns {token, user}. */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/otp.php';
require_method('POST');

$body = json_body();
$email = strtolower(trim(require_field($body, 'email')));
$code = preg_replace('/\D/', '', require_field($body, 'code'));

$row = otp_verify($email, 'login', $code); // sends the JSON error itself on failure

$stmt = db()->prepare("SELECT id, name, email, phone, role, must_change_password FROM users WHERE email = :e AND role = 'rider' AND is_active = 1");
$stmt->execute(['e' => $email]);
$user = $stmt->fetch();
if (!$user) json_error('This code has expired or was not requested. Please request a new one.', 400);

otp_consume($row['id']);
$user['must_change_password'] = (bool)$user['must_change_password'];
json_out(['token' => issue_session($user['id']), 'user' => $user]);
