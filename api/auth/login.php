<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/otp.php';
require_once __DIR__ . '/../../lib/login_guard.php';
require_method('POST');

$body = json_body();
$email = strtolower(trim(require_field($body, 'email')));
$password = require_field($body, 'password');

login_rate_limit_check($email);

$pdo = db();
$stmt = $pdo->prepare('SELECT id, name, email, phone, role, password_hash, is_active, must_change_password FROM users WHERE email = :email');
$stmt->execute(['email' => $email]);
$user = $stmt->fetch();

// Same response (and same rate-limit accounting) whether the account exists or not,
// so this endpoint can't be used to find out which emails are registered.
if (!$user || !password_verify($password, $user['password_hash'])) {
    login_attempt_record($email);
    json_error('Invalid email or password.', 401);
}

if ((int)$user['is_active'] === 0) {
    json_error('This account has been disabled. Please contact the admin.', 403);
}

// Riders must confirm an emailed 6-digit code on EVERY sign-in (2-step). The password alone
// never starts a rider session: no token is issued here, only the code is sent. The session
// starts in api/rider/verify-otp.php once the code is entered.
if ($user['role'] === 'rider') {
    try {
        $code = otp_issue($email, 'login');
        otp_send_email($email, 'login', $code, $user['name']);
    } catch (OtpThrottled $e) {
        // Inside the resend cooldown the previous code is still valid — just let them enter it.
        // (Hourly send cap is a real error.)
        if ($e->retryAfter > OTP_RESEND_COOLDOWN) {
            json_error($e->getMessage(), 429);
        }
        json_out(['otp_required' => true, 'email' => $email, 'expires_in' => OTP_TTL_MINUTES * 60, 'resend_in' => $e->retryAfter]);
    } catch (Throwable $e) {
        error_log('[otp mail:login] ' . $e->getMessage());
        json_error('We could not send the verification code. Please try again.' . mail_error_detail($e), 502);
    }
    json_out(['otp_required' => true, 'email' => $email, 'expires_in' => OTP_TTL_MINUTES * 60, 'resend_in' => OTP_RESEND_COOLDOWN]);
}

// Admin & staff: one active session per account. The password is already verified at this
// point, so it's safe to say "already logged in". The existing session is left untouched.
try {
    $token = issue_session($user['id'], $user['role']);
} catch (ActiveSessionExists $e) {
    login_attempt_record($email); // counts toward the rate limit so a duplicate-login loop can't flood the admin
    notify_admin_duplicate_login($user);
    json_out([
        'error' => 'Login Failed: This account is already logged in on another device or browser. Please log out from the active session before attempting to log in again.',
        'code' => 'DUPLICATE_SESSION',
    ], 409);
}

login_attempts_clear($email);
unset($user['password_hash']);
unset($user['is_active']);
$user['must_change_password'] = (bool)$user['must_change_password'];

json_out(['token' => $token, 'user' => $user]);
