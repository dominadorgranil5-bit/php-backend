<?php
/**
 * One-time migration: Single Active Session for admin & staff.
 *  - sessions.single_user_id + UNIQUE index  (one active session per admin/staff account, atomic)
 *  - session expiry: admin 2 hours, staff 12 hours (existing sessions are adjusted to match)
 *  - existing session tokens are hashed in place (SHA-256), so nobody is logged out by this migration
 *  - login_attempts (rate limiting) and admin_notifications (dashboard bell) tables
 * Safe to run more than once.   Run:  php migrate-single-session.php   (or open it once in the browser)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';

$pdo = db();

$columnExists = (int)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sessions' AND COLUMN_NAME = 'single_user_id'"
)->fetchColumn() > 0;

if (!$columnExists) {
    $pdo->exec('ALTER TABLE sessions MODIFY expires_at DATETIME NULL, ADD COLUMN single_user_id CHAR(36) NULL AFTER user_id');
    // Store only hashes from now on (lib/auth.php hashes the incoming token the same way).
    // This must run exactly once, which is why it is tied to the column being created above.
    $pdo->exec('UPDATE sessions SET token = SHA2(token, 256)');
}

// --- everything below is idempotent -------------------------------------------------------

// If an admin/staff account somehow has several sessions today, keep only the newest one.
$multi = $pdo->query(
    "SELECT s.user_id FROM sessions s JOIN users u ON u.id = s.user_id
     WHERE u.role IN ('admin','staff') GROUP BY s.user_id HAVING COUNT(*) > 1"
)->fetchAll(PDO::FETCH_COLUMN);
foreach ($multi as $uid) {
    $keep = $pdo->prepare('SELECT token FROM sessions WHERE user_id = :u ORDER BY created_at DESC, token DESC LIMIT 1');
    $keep->execute(['u' => $uid]);
    $pdo->prepare('DELETE FROM sessions WHERE user_id = :u AND token <> :t')
        ->execute(['u' => $uid, 't' => $keep->fetchColumn()]);
}

$pdo->exec("UPDATE sessions s JOIN users u ON u.id = s.user_id
            SET s.single_user_id = s.user_id
            WHERE u.role IN ('admin','staff') AND s.single_user_id IS NULL");
$pdo->exec("UPDATE sessions s JOIN users u ON u.id = s.user_id
            SET s.expires_at = LEAST(COALESCE(s.expires_at, NOW() + INTERVAL 12 HOUR), NOW() + INTERVAL 12 HOUR)
            WHERE u.role = 'staff'");
$pdo->exec("UPDATE sessions s JOIN users u ON u.id = s.user_id
            SET s.expires_at = LEAST(s.expires_at, NOW() + INTERVAL 120 MINUTE)
            WHERE u.role = 'admin' AND s.expires_at IS NOT NULL");

$indexExists = (int)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sessions' AND INDEX_NAME = 'uq_sessions_single_user'"
)->fetchColumn() > 0;
if (!$indexExists) {
    $pdo->exec('ALTER TABLE sessions ADD UNIQUE KEY uq_sessions_single_user (single_user_id)');
}

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS login_attempts (
       id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
       ident_hash   CHAR(64)        NOT NULL,
       ip_address   VARCHAR(45)     NOT NULL,
       attempted_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
       KEY idx_login_attempts_ident (ident_hash, attempted_at),
       KEY idx_login_attempts_ip (ip_address, attempted_at)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS admin_notifications (
       id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
       type          VARCHAR(40)     NOT NULL,
       account_email VARCHAR(190)    NOT NULL,
       account_name  VARCHAR(150)    NOT NULL DEFAULT '',
       account_role  VARCHAR(20)     NOT NULL,
       result        VARCHAR(80)     NOT NULL,
       ip_address    VARCHAR(45)     NULL,
       is_read       TINYINT(1)      NOT NULL DEFAULT 0,
       created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
       read_at       DATETIME        NULL,
       KEY idx_admin_notifications_unread (is_read, created_at)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

echo "Done - single active session is ready.\n";
