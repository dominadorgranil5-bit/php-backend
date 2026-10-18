<?php
/** Sends a fresh signup code for a pending registration (keeps the details already entered). */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/otp.php';
require_method('POST');

$body = json_body();
$email = strtolower(trim(require_field($body, 'email')));

if (!otp_exists($email, 'register')) {
    json_error('Your signup session expired. Please fill in the form again.', 400);
}

$stmt = db()->prepare('SELECT id FROM users WHERE email = :email');
$stmt->execute(['email' => $email]);
if ($stmt->fetch()) {
    json_error('An account with that email already exists.', 409);
}

try {
    $code = otp_issue($email, 'register');
} catch (OtpThrottled $e) {
    json_error($e->getMessage(), 429);
}

$stmt = db()->prepare('SELECT pending_name FROM email_otps WHERE email = :e AND purpose = "register"');
$stmt->execute(['e' => $email]);
$name = (string)($stmt->fetchColumn() ?: '');

try {
    otp_send_email($email, 'register', $code, $name);
} catch (Throwable $e) {
    error_log('[otp mail:resend] ' . $e->getMessage());
    json_error('We could not send the verification email. Please try again.' . mail_error_detail($e), 502);
}

json_out(['ok' => true, 'expires_in' => OTP_TTL_MINUTES * 60, 'resend_in' => OTP_RESEND_COOLDOWN]);
