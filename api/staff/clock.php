<?php
/** POST {action: 'in'|'out'} — staff/rider Clock In / Clock Out for today. Returns today's record. */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/attendance_helpers.php';
require_method('POST');
$user = require_role(['staff', 'rider']);

$action = require_field(json_body(), 'action');
if (!in_array($action, ['in', 'out'], true)) {
    json_error("action must be 'in' or 'out'.", 400);
}

$error = attendance_manual($user, $action);
if ($error !== null) {
    json_error($error, 400);
}

$stmt = db()->prepare(
    'SELECT user_id, work_date, first_in, last_seen, time_out, ot_requested_at, ot_status FROM attendance WHERE user_id = :u AND work_date = CURDATE()'
);
$stmt->execute(['u' => $user['id']]);
json_out(['ok' => true, 'record' => $stmt->fetch() ?: null]);
