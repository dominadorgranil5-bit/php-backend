<?php
/**
 * lib/otp.php — 6-digit email OTP for signup verification ('register')
 * and password recovery ('reset').
 *
 * - Only a bcrypt hash of the code is stored, never the code itself.
 * - One active code per (email, purpose). A new code replaces the old one.
 * - Code expires in OTP_TTL_MINUTES, max OTP_MAX_ATTEMPTS wrong tries.
 * - Resend cooldown + hourly send cap to stop email spamming.
 * - All time math is done by MySQL (NOW()) so PHP/MySQL timezones can't disagree.
 */

require_once __DIR__ . '/mailer.php';

const OTP_TTL_MINUTES = 10;
const OTP_MAX_ATTEMPTS = 5;
const OTP_RESEND_COOLDOWN = 60;      // seconds
const OTP_MAX_SENDS_PER_HOUR = 5;

class OtpThrottled extends RuntimeException
{
    public function __construct(public int $retryAfter, string $message)
    {
        parent::__construct($message, 429);
    }
}

/**
 * Creates/refreshes the OTP row and returns the plain 6-digit code (to email it).
 * $pending = ['name'=>, 'phone'=>, 'password_hash'=>] for signups; null keeps
 * whatever pending data is already stored (used by "resend").
 * @throws OtpThrottled
 */
function otp_issue(string $email, string $purpose, ?array $pending = null): string
{
    $pdo = db();
    $pdo->exec('DELETE FROM email_otps WHERE expires_at < (NOW() - INTERVAL 1 DAY)');

    $stmt = $pdo->prepare(
        'SELECT id, send_count,
                TIMESTAMPDIFF(SECOND, last_sent_at, NOW()) AS since_last,
                TIMESTAMPDIFF(SECOND, window_start, NOW()) AS since_window
         FROM email_otps WHERE email = :email AND purpose = :purpose'
    );
    $stmt->execute(['email' => $email, 'purpose' => $purpose]);
    $row = $stmt->fetch();

    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $hash = password_hash($code, PASSWORD_DEFAULT);

    if (!$row) {
        $stmt = $pdo->prepare(
            'INSERT INTO email_otps
               (id, email, purpose, code_hash, pending_name, pending_phone, pending_password_hash,
                attempts, send_count, window_start, last_sent_at, expires_at)
             VALUES
               (:id, :email, :purpose, :hash, :name, :phone, :pw,
                0, 1, NOW(), NOW(), DATE_ADD(NOW(), INTERVAL ' . OTP_TTL_MINUTES . ' MINUTE))'
        );
        $stmt->execute([
            'id' => make_uuid(), 'email' => $email, 'purpose' => $purpose, 'hash' => $hash,
            'name' => $pending['name'] ?? null,
            'phone' => $pending['phone'] ?? null,
            'pw' => $pending['password_hash'] ?? null,
        ]);
        return $code;
    }

    $sinceLast = (int)$row['since_last'];
    $sinceWindow = (int)$row['since_window'];

    if ($sinceLast < OTP_RESEND_COOLDOWN) {
        $wait = OTP_RESEND_COOLDOWN - $sinceLast;
        throw new OtpThrottled($wait, "Please wait {$wait} seconds before requesting a new code.");
    }

    $newWindow = $sinceWindow >= 3600;
    $sendCount = $newWindow ? 0 : (int)$row['send_count'];
    if ($sendCount >= OTP_MAX_SENDS_PER_HOUR) {
        throw new OtpThrottled(3600 - $sinceWindow, 'Too many code requests. Please try again in a while.');
    }

    $sql = 'UPDATE email_otps SET code_hash = :hash, attempts = 0, send_count = :sc,
                   window_start = ' . ($newWindow ? 'NOW()' : 'window_start') . ',
                   last_sent_at = NOW(),
                   expires_at = DATE_ADD(NOW(), INTERVAL ' . OTP_TTL_MINUTES . ' MINUTE)';
    $params = ['hash' => $hash, 'sc' => $sendCount + 1, 'id' => $row['id']];
    if ($pending !== null) {
        $sql .= ', pending_name = :name, pending_phone = :phone, pending_password_hash = :pw';
        $params += ['name' => $pending['name'], 'phone' => $pending['phone'], 'pw' => $pending['password_hash']];
    }
    $pdo->prepare($sql . ' WHERE id = :id')->execute($params);

    return $code;
}

/** True if a (possibly expired) OTP row exists — used by "resend" for signups. */
function otp_exists(string $email, string $purpose): bool
{
    $stmt = db()->prepare('SELECT 1 FROM email_otps WHERE email = :e AND purpose = :p');
    $stmt->execute(['e' => $email, 'p' => $purpose]);
    return (bool)$stmt->fetchColumn();
}

/** Checks the code. Returns the OTP row on success, otherwise sends a JSON error and exits. */
function otp_verify(string $email, string $purpose, string $code): array
{
    $pdo = db();
    $stmt = $pdo->prepare(
        'SELECT * FROM email_otps WHERE email = :e AND purpose = :p AND expires_at > NOW()'
    );
    $stmt->execute(['e' => $email, 'p' => $purpose]);
    $row = $stmt->fetch();

    if (!$row) {
        json_error('This code has expired or was not requested. Please request a new one.', 400);
    }

    if ((int)$row['attempts'] >= OTP_MAX_ATTEMPTS) {
        $pdo->prepare('DELETE FROM email_otps WHERE id = :id')->execute(['id' => $row['id']]);
        json_error('Too many incorrect attempts. Please request a new code.', 429);
    }

    if (!preg_match('/^\d{6}$/', $code) || !password_verify($code, $row['code_hash'])) {
        $pdo->prepare('UPDATE email_otps SET attempts = attempts + 1 WHERE id = :id')->execute(['id' => $row['id']]);
        $left = OTP_MAX_ATTEMPTS - ((int)$row['attempts'] + 1);
        if ($left <= 0) {
            $pdo->prepare('DELETE FROM email_otps WHERE id = :id')->execute(['id' => $row['id']]);
            json_error('Too many incorrect attempts. Please request a new code.', 429);
        }
        json_error("Incorrect code. {$left} attempt" . ($left === 1 ? '' : 's') . ' left.', 400);
    }

    return $row;
}

/** Single-use: deletes the row; fails if someone else already consumed it. */
function otp_consume(string $id): void
{
    $stmt = db()->prepare('DELETE FROM email_otps WHERE id = :id');
    $stmt->execute(['id' => $id]);
    if ($stmt->rowCount() !== 1) {
        json_error('This code was already used. Please request a new one.', 400);
    }
}

/** Builds and sends the OTP email. Throws on SMTP failure. */
function otp_send_email(string $email, string $purpose, string $code, string $name = ''): void
{
    $app = (string)config('APP_NAME', 'Drew\'s Cafe');
    $safeApp = htmlspecialchars($app, ENT_QUOTES, 'UTF-8');
    $hello = $name !== '' ? 'Hi ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ',' : 'Hi,';
    $helloText = $name !== '' ? "Hi $name," : 'Hi,';
    $mins = OTP_TTL_MINUTES;

    if ($purpose === 'register') {
        $subject = "$code is your $app verification code";
        $intro = "Use this code to verify your email and finish creating your $safeApp account.";
        $introText = "Use this code to verify your email and finish creating your $app account.";
    } elseif ($purpose === 'login') {
        $subject = "$code is your $app rider sign-in code";
        $intro = "Use this code to sign in to the $safeApp Rider App.";
        $introText = "Use this code to sign in to the $app Rider App.";
    } else {
        $subject = "$code is your $app password reset code";
        $intro = "Use this code to reset your $safeApp password.";
        $introText = "Use this code to reset your $app password.";
    }

    $html = <<<HTML
<div style="font-family:Arial,Helvetica,sans-serif;background:#F8F5F0;padding:24px;">
  <div style="max-width:420px;margin:0 auto;background:#ffffff;border-radius:16px;padding:28px;color:#3D3028;">
    <h2 style="margin:0 0 12px;color:#6B4226;">{$safeApp}</h2>
    <p style="margin:0 0 8px;">{$hello}</p>
    <p style="margin:0 0 20px;">{$intro}</p>
    <div style="font-size:34px;font-weight:800;letter-spacing:8px;text-align:center;background:#F3E9DE;border-radius:12px;padding:16px 0;">{$code}</div>
    <p style="margin:20px 0 0;font-size:13px;color:#78695D;">This code expires in {$mins} minutes. Never share it with anyone. If you didn't request it, you can safely ignore this email.</p>
  </div>
</div>
HTML;

    $text = "$helloText\n\n$introText\n\nYour code: $code\n\nIt expires in $mins minutes. Never share it with anyone. If you didn't request it, ignore this email.";

    send_mail($email, $subject, $html, $text);
}
