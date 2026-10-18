<?php
/** POST {user_id, work_date, decision: 'approve'|'reject'} — admin-only. Decides a pending overtime request. */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_method('POST');
require_role(['admin']);

$body = json_body();
$userId = require_field($body, 'user_id');
$workDate = require_field($body, 'work_date');
$decision = require_field($body, 'decision');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) {
    json_error('work_date must be YYYY-MM-DD.', 400);
}
if (!in_array($decision, ['approve', 'reject'], true)) {
    json_error("decision must be 'approve' or 'reject'.", 400);
}

$pdo = db();
$stmt = $pdo->prepare(
    "UPDATE attendance SET ot_status = :s, ot_decided_at = NOW()
     WHERE user_id = :u AND work_date = :d AND ot_status = 'pending'"
);
$stmt->execute(['s' => $decision === 'approve' ? 'approved' : 'rejected', 'u' => $userId, 'd' => $workDate]);
if ($stmt->rowCount() === 0) {
    json_error('No pending overtime request found for that day.', 404);
}

$row = $pdo->prepare('SELECT user_id, work_date, first_in, last_seen, time_out, ot_requested_at, ot_status FROM attendance WHERE user_id = :u AND work_date = :d');
$row->execute(['u' => $userId, 'd' => $workDate]);
json_out(['ok' => true, 'record' => $row->fetch()]);
