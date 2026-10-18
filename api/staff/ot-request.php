<?php
/** POST — staff/rider overtime request for today. Only after Clock Out with 10+ hours worked. */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/attendance_helpers.php';
require_method('POST');
$user = require_role(['staff', 'rider']);

$error = attendance_request_ot($user);
if ($error !== null) {
    json_error($error, 400);
}

$stmt = db()->prepare(
    'SELECT user_id, work_date, first_in, last_seen, time_out, ot_requested_at, ot_status FROM attendance WHERE user_id = :u AND work_date = CURDATE()'
);
$stmt->execute(['u' => $user['id']]);
json_out(['ok' => true, 'record' => $stmt->fetch() ?: null]);
