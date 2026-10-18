<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_method('GET');
require_role(['admin']);

$pdo = db();
$today = (string)$pdo->query('SELECT CURDATE()')->fetchColumn();

$month = trim((string)($_GET['month'] ?? substr($today, 0, 7)));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
    json_error('month must be YYYY-MM.', 400);
}

$start = $month . '-01';
$end = date('Y-m-t', strtotime($start));

$stmt = $pdo->prepare(
    'SELECT user_id, work_date, first_in, last_seen, time_out, ot_requested_at, ot_status
     FROM attendance
     WHERE work_date BETWEEN :s AND :e
     ORDER BY work_date DESC, first_in DESC'
);
$stmt->execute(['s' => $start, 'e' => $end]);

json_out(['month' => $month, 'today' => $today, 'records' => $stmt->fetchAll()]);
