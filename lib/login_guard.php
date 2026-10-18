<?php
/**
 * lib/login_guard.php — login rate limiting + admin notification for blocked duplicate logins.
 * No passwords or tokens are ever written here; the email is stored only as a SHA-256 hash in
 * login_attempts, and as a plain account identifier in admin_notifications (admin-only table).
 */

const LOGIN_MAX_PER_ACCOUNT = 5;   // failed/blocked attempts per account per window
const LOGIN_MAX_PER_IP = 20;       // per IP (higher: the cafe's staff share one wifi/IP)
const LOGIN_WINDOW_MINUTES = 15;

function login_client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

function login_ident_hash(string $email): string
{
    return hash('sha256', strtolower(trim($email)));
}

/** 429s when this account or IP made too many recent failed/blocked attempts. */
function login_rate_limit_check(string $email): void
{
    // Per-IP count (any account) and per-account count (any IP, so a distributed guessing attack is caught too).
    $ip = db()->prepare(
        'SELECT COUNT(*) FROM login_attempts
         WHERE ip_address = :ip AND attempted_at > (NOW() - INTERVAL ' . LOGIN_WINDOW_MINUTES . ' MINUTE)'
    );
    $ip->execute(['ip' => login_client_ip()]);

    $acc = db()->prepare(
        'SELECT COUNT(*) FROM login_attempts
         WHERE ident_hash = :h AND attempted_at > (NOW() - INTERVAL ' . LOGIN_WINDOW_MINUTES . ' MINUTE)'
    );
    $acc->execute(['h' => login_ident_hash($email)]);

    if ((int)$acc->fetchColumn() >= LOGIN_MAX_PER_ACCOUNT || (int)$ip->fetchColumn() >= LOGIN_MAX_PER_IP) {
        header('Retry-After: ' . (LOGIN_WINDOW_MINUTES * 60));
        json_out([
            'error' => 'Too many login attempts. Please wait a few minutes and try again.',
            'code' => 'RATE_LIMITED',
        ], 429);
    }
}

/** Call for every wrong-password attempt (existing account or not — same behaviour) and every blocked duplicate. */
function login_attempt_record(string $email): void
{
    $pdo = db();
    $pdo->prepare('INSERT INTO login_attempts (ident_hash, ip_address) VALUES (:h, :ip)')
        ->execute(['h' => login_ident_hash($email), 'ip' => login_client_ip()]);

    if (random_int(1, 50) === 1) { // cheap housekeeping
        $pdo->exec('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)');
    }
}

function login_attempts_clear(string $email): void
{
    db()->prepare('DELETE FROM login_attempts WHERE ident_hash = :h')
        ->execute(['h' => login_ident_hash($email)]);
}

/** Dashboard notification for the admin. Only call AFTER the password was verified. */
function notify_admin_duplicate_login(array $user): void
{
    try {
        db()->prepare(
            "INSERT INTO admin_notifications (type, account_email, account_name, account_role, result, ip_address)
             VALUES ('duplicate_login', :email, :name, :role, 'Duplicate Login Attempt Blocked', :ip)"
        )->execute([
            'email' => (string)$user['email'],
            'name' => (string)$user['name'],
            'role' => (string)$user['role'],
            'ip' => login_client_ip(),
        ]);
    } catch (Throwable $e) {
        error_log('[admin notification] ' . $e->getMessage()); // never let this break the login response
    }
}
