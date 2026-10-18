<?php
/**
 * Password recovery step 1: checks that a customer account exists for the
 * email, and only then emails a 6-digit code. Unknown emails get a 404 and
 * no code is sent. (Only 'customer' accounts can recover this way; staff/admin
 * passwords are managed by an admin.)
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/otp.php';
require_method('POST');

$body = json_body();
$email = strtolower(trim(require_field($body, 'email')));

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_error('Invalid email address.', 400);
}

$stmt = db()->prepare('SELECT id, name, role FROM users WHERE email = :email');
$stmt->execute(['email' => $email]);
$user = $stmt->fetch();

// Check the email first — no code is generated or emailed unless a
// customer account exists for it.
if (!$user) {
    json_error('No account found with that email address.', 404);
}
if ($user['role'] !== 'customer') {
    json_error('This account cannot be reset here. Please contact the store admin.', 403);
}

try {
    $code = otp_issue($email, 'reset');
} catch (OtpThrottled $e) {
    json_error($e->getMessage(), 429);
}

try {
    otp_send_email($email, 'reset', $code, $user['name']);
} catch (Throwable $e) {
    error_log('[otp mail:reset] ' . $e->getMessage());
    json_error('We could not send the email. Please try again.' . mail_error_detail($e), 502);
}

json_out(['ok' => true, 'expires_in' => OTP_TTL_MINUTES * 60, 'resend_in' => OTP_RESEND_COOLDOWN]);
