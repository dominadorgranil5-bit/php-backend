<?php
/**
 * Staff / rider attendance ("pasok").
 * Recorded ONLY through the Clock In / Clock Out buttons on the Attendance page (api/staff/clock.php).
 * Signing in or out does not touch attendance. One record per user per day; all time math uses
 * MySQL (CURDATE/NOW) so PHP and MySQL timezones can't disagree.
 */

/**
 * Explicit Clock In / Clock Out buttons (Attendance page). Unlike the automatic sign-in/out
 * above, these work for riders too. Returns an error message, or null on success.
 */
function attendance_manual(array $user, string $action): ?string
{
    if (!in_array($user['role'] ?? '', ['staff', 'rider'], true)) {
        return 'Attendance is only for staff and riders.';
    }
    $pdo = db();
    if ($action === 'in') {
        // One Clock In per day: after Clock Out the next Clock In is the following day.
        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO attendance (user_id, work_date, first_in, last_seen)
             VALUES (:uid, CURDATE(), NOW(), NOW())'
        );
        $stmt->execute(['uid' => $user['id']]);
        return $stmt->rowCount() > 0 ? null : 'You already clocked in today. You can clock in again tomorrow.';
    }
    $stmt = $pdo->prepare(
        'UPDATE attendance SET time_out = NOW(), last_seen = NOW()
         WHERE user_id = :uid AND work_date = CURDATE() AND time_out IS NULL'
    );
    $stmt->execute(['uid' => $user['id']]);
    return $stmt->rowCount() > 0 ? null : 'You are not clocked in right now.';
}

/**
 * Overtime request for today. Allowed only after Clock Out and only when the day's worked time
 * (time in to time out) is 10 hours or more. Returns an error message, or null on success.
 */
function attendance_request_ot(array $user): ?string
{
    if (!in_array($user['role'] ?? '', ['staff', 'rider'], true)) {
        return 'Attendance is only for staff and riders.';
    }
    $pdo = db();
    $stmt = $pdo->prepare(
        'SELECT time_out IS NOT NULL AS out_done, ot_requested_at,
                TIMESTAMPDIFF(MINUTE, first_in, time_out) AS worked_min
         FROM attendance WHERE user_id = :uid AND work_date = CURDATE()'
    );
    $stmt->execute(['uid' => $user['id']]);
    $row = $stmt->fetch();
    if (!$row) {
        return 'You have no attendance record today.';
    }
    if (!(int)$row['out_done']) {
        return 'Clock Out first before requesting overtime.';
    }
    if ($row['ot_requested_at'] !== null) {
        return 'You already requested overtime today.';
    }
    if ((int)$row['worked_min'] < 600) {
        return 'Overtime can only be requested after working 10 hours or more.';
    }
    $pdo->prepare(
        "UPDATE attendance SET ot_requested_at = NOW(), ot_status = 'pending', ot_decided_at = NULL
         WHERE user_id = :uid AND work_date = CURDATE()"
    )->execute(['uid' => $user['id']]);

    // Bell notification for the admin (never lets a notification problem fail the request).
    try {
        $pdo->prepare(
            "INSERT INTO admin_notifications (type, account_email, account_name, account_role, result)
             VALUES ('ot_request', :email, :name, :role, 'Overtime Request')"
        )->execute([
            'email' => (string)$user['email'],
            'name' => (string)$user['name'],
            'role' => (string)$user['role'],
        ]);
    } catch (Throwable $e) {
        error_log('[ot notification] ' . $e->getMessage());
    }
    return null;
}
