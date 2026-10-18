<?php
/** GET — admin-only. Latest customer feedback (newest first) + the average rating. */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_method('GET');
require_role(['admin']);

$pdo = db();
$rows = $pdo->query(
    'SELECT id, customer_name, customer_email, rating, message, created_at
     FROM customer_feedback
     ORDER BY created_at DESC, id DESC
     LIMIT 200'
)->fetchAll();

foreach ($rows as &$r) {
    $r['id'] = (int)$r['id'];
    $r['rating'] = $r['rating'] === null ? null : (int)$r['rating'];
}
unset($r);

$avg = $pdo->query('SELECT AVG(rating) FROM customer_feedback WHERE rating IS NOT NULL')->fetchColumn();
$total = (int)$pdo->query('SELECT COUNT(*) FROM customer_feedback')->fetchColumn();

json_out([
    'total' => $total,
    'average_rating' => $avg === null || $avg === false ? null : round((float)$avg, 1),
    'feedback' => $rows,
]);
