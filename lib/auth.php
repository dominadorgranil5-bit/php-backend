<?php
/**
 * lib/auth.php — replaces Supabase Auth with simple opaque bearer tokens
 * stored in the `sessions` table. The frontend keeps the token (instead of
 * a Supabase JWT) and sends it as: Authorization: Bearer <token>
 */

const SESSION_TTL_DAYS = 30;          // customers / riders
const ADMIN_SESSION_MINUTES = 120;    // admin: expires 2 hours after login
const STAFF_SESSION_HOURS = 12;       // staff: expires 12 hours after login (covers a forgotten log-out)

// Web browser sessions (requests carrying the X-Web-Session header) are ended automatically when the
// browser/tab is closed: the web app pings every few seconds and sends a "bye" beacon on pagehide.
const WEB_HEARTBEAT_TIMEOUT_SECONDS = 150; // no ping for this long (crash, sleep, no network) => session over
const WEB_CLOSE_GRACE_SECONDS = 15;        // after a "bye", any request within this window (page refresh) keeps the session

/** SQL condition (table alias s) that is TRUE for web sessions whose browser has gone away. Native sessions have last_seen NULL and are never matched. */
function web_session_dead_sql(string $alias = 's'): string
{
    return "(($alias.last_seen IS NOT NULL AND $alias.last_seen < NOW() - INTERVAL " . WEB_HEARTBEAT_TIMEOUT_SECONDS . " SECOND)"
         . " OR ($alias.closing_at IS NOT NULL AND $alias.closing_at < NOW() - INTERVAL " . WEB_CLOSE_GRACE_SECONDS . " SECOND))";
}

/** Roles that may only hold ONE active session at a time. */
const SINGLE_SESSION_ROLES = ['admin', 'staff'];

/** Thrown by issue_session() when an admin/staff account already has an active session. */
final class ActiveSessionExists extends RuntimeException {}

/** Only this SHA-256 hash is stored in the DB — a leaked `sessions` table can't be replayed as tokens. */
function hash_session_token(string $token): string
{
    return hash('sha256', $token);
}

/**
 * Creates a session and returns the RAW token (given to the client once, never stored).
 * Admin/staff: atomic one-session-per-account — throws ActiveSessionExists if one is already
 * active, and never touches that existing session. Enforced by the UNIQUE index on
 * sessions.single_user_id, so two simultaneous logins can't both succeed.
 */
function issue_session(string $userId, string $role = 'customer'): string
{
    $pdo = db();
    $token = bin2hex(random_bytes(32)); // 64 hex chars
    $single = in_array($role, SINGLE_SESSION_ROLES, true);

    // Expiry is computed by MySQL (NOW()) so PHP/MySQL timezones can never disagree.
    if ($role === 'admin') {
        $expiresSql = 'NOW() + INTERVAL ' . ADMIN_SESSION_MINUTES . ' MINUTE';
    } elseif ($role === 'staff') {
        $expiresSql = 'NOW() + INTERVAL ' . STAFF_SESSION_HOURS . ' HOUR';
    } else {
        $expiresSql = 'NOW() + INTERVAL ' . SESSION_TTL_DAYS . ' DAY';
    }

    $insert = $pdo->prepare(
        "INSERT INTO sessions (token, user_id, single_user_id, expires_at)
         VALUES (:token, :user_id, :single, $expiresSql)"
    );
    $params = [
        'token' => hash_session_token($token),
        'user_id' => $userId,
        'single' => $single ? $userId : null,
    ];

    if (!$single) {
        $insert->execute($params);
        return $token;
    }

    $pdo->beginTransaction();
    try {
        // Only THIS account's session, and only if it has already expired (admin's 1-hour limit).
        // A still-valid session is never deleted here.
        // Also clears a session whose browser was closed (heartbeat stopped), so the account is free again.
        $pdo->prepare(
            'DELETE s FROM sessions s WHERE s.user_id = :u
             AND ((s.expires_at IS NOT NULL AND s.expires_at <= NOW()) OR ' . web_session_dead_sql('s') . ')'
        )->execute(['u' => $userId]);

        $insert->execute($params);
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ((int)($e->errorInfo[1] ?? 0) === 1062) { // duplicate key on uq_sessions_single_user
            throw new ActiveSessionExists();
        }
        throw $e;
    }

    return $token;
}

function bearer_token(): ?string
{
    // Header lookup is case-insensitive on purpose: some clients (e.g. the
    // mobile app's okhttp client) send "authorization" in lowercase, and
    // apache_request_headers() preserves whatever casing the client used —
    // it does NOT normalize it. A hardcoded ['Authorization'] key lookup
    // silently misses those requests.
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? null;

    if (!$header && function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) {
                $header = $value;
                break;
            }
        }
    }

    if ($header && str_starts_with($header, 'Bearer ')) {
        return substr($header, 7);
    }

    // The browser's EventSource API (used by the live orders stream) can't
    // set custom headers, so it passes the token as ?token=... instead.
    $queryToken = $_GET['token'] ?? null;
    return is_string($queryToken) && $queryToken !== '' ? $queryToken : null;
}

/** Returns the signed-in user's profile row, or null if no/expired token. */
function current_user(): ?array
{
    $token = bearer_token();
    if (!$token) {
        return null;
    }

    $pdo = db();
    $hash = hash_session_token($token);
    $stmt = $pdo->prepare(
        'SELECT u.id, u.name, u.email, u.phone, u.role, u.must_change_password
         FROM sessions s
         JOIN users u ON u.id = s.user_id
         WHERE s.token = :token AND (s.expires_at IS NULL OR s.expires_at > NOW()) AND u.is_active = 1
           AND NOT ' . web_session_dead_sql('s')
    );
    $stmt->execute(['token' => $hash]);
    $user = $stmt->fetch();

    if (!$user) {
        // Browser went away (no heartbeat / "bye" not cancelled) => delete the session now so
        // admin/staff can log in again right away.
        db()->prepare('DELETE s FROM sessions s WHERE s.token = :token AND ' . web_session_dead_sql('s'))
            ->execute(['token' => $hash]);
        return null;
    }

    // Request from the web app: refresh the heartbeat and cancel a pending "bye" (page refresh).
    if (!empty($_SERVER['HTTP_X_WEB_SESSION'])) {
        $pdo->prepare(
            'UPDATE sessions SET last_seen = NOW(), closing_at = NULL
             WHERE token = :token AND (last_seen IS NULL OR closing_at IS NOT NULL OR last_seen < NOW() - INTERVAL 5 SECOND)'
        )->execute(['token' => $hash]);
    }

    $user['must_change_password'] = (bool)$user['must_change_password'];
    return $user;
}

/** 401s if not signed in; otherwise returns the user row. */
function require_user(): array
{
    $user = current_user();
    if (!$user) {
        // `code` lets the frontend tell "session ended/expired" apart from a normal failed request.
        json_out(['error' => 'Not authenticated', 'code' => 'SESSION_INVALID'], 401);
    }
    return $user;
}

/** 401s if not signed in, 403s if role isn't in $roles; otherwise returns the user row. */
function require_role(array $roles): array
{
    $user = require_user();
    if (!in_array($user['role'], $roles, true)) {
        json_error('Forbidden', 403);
    }
    return $user;
}

function revoke_session(): void
{
    $token = bearer_token();
    if (!$token) {
        return;
    }
    $stmt = db()->prepare('DELETE FROM sessions WHERE token = :token');
    $stmt->execute(['token' => hash_session_token($token)]);
}

/** Browser tab/window is closing (sendBeacon). Starts the grace window; a refresh cancels it, otherwise the session ends. */
function mark_session_closing(): void
{
    $token = bearer_token();
    if (!$token) {
        return;
    }
    // Only web-heartbeat sessions (last_seen set) are affected; a native-app session is never touched.
    db()->prepare(
        'UPDATE sessions SET closing_at = COALESCE(closing_at, NOW()) WHERE token = :token AND last_seen IS NOT NULL'
    )->execute(['token' => hash_session_token($token)]);
}
