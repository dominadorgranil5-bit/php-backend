<?php
/**
 * Step 1 of signup. Does NOT create the account yet — it validates the
 * details, stores them (password already hashed) as a pending signup, and
 * emails a 6-digit code. The account is created in verify-register.php.
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/otp.php';
require_once __DIR__ . '/../../lib/password_validation.php';
require_method('POST');

$body = json_body();
$name = require_field($body, 'name');
$email = strtolower(trim(require_field($body, 'email')));
$phone = trim((string)($body['phone'] ?? ''));
$password = require_field($body, 'password');

if (!preg_match('/^\d{11}$/', $phone)) {
    json_error('Mobile number must be exactly 11 digits (numbers only).', 400);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_error('Invalid email address.', 400);
}
require_strong_password($password); // customers: length + uppercase + lowercase + number

$pdo = db();

$stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email');
$stmt->execute(['email' => $email]);
if ($stmt->fetch()) {
    json_error('An account with that email already exists.', 409);
}

try {
    $code = otp_issue($email, 'register', [
        'name' => $name,
        'phone' => $phone,
        'password_hash' => password_hash($password, PASSWORD_BCRYPT),
    ]);
} catch (OtpThrottled $e) {
    json_error($e->getMessage(), 429);
}

try {
    otp_send_email($email, 'register', $code, $name);
} catch (Throwable $e) {
    error_log('[otp mail:register] ' . $e->getMessage());
    json_error('We could not send the verification email. Please check the address and try again.' . mail_error_detail($e), 502);
}

json_out([
    'ok' => true,
    'requires_otp' => true,
    'email' => $email,
    'expires_in' => OTP_TTL_MINUTES * 60,
    'resend_in' => OTP_RESEND_COOLDOWN,
]);