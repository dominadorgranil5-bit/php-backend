<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/expenses_helpers.php';
require_method('GET');
require_role(['staff', 'admin']);

$id = trim((string)($_GET['id'] ?? ''));
if ($id === '') {
    json_error('Missing expense id.', 400);
}

$stmt = db()->prepare('SELECT receipt_path, receipt_name, receipt_mime FROM expenses WHERE id = :id');
$stmt->execute(['id' => $id]);
$row = $stmt->fetch();

if (!$row || !$row['receipt_path']) {
    json_error('No receipt on file for this expense.', 404);
}

// basename() so a tampered DB value can never escape the receipts folder.
$path = expense_receipt_dir() . '/' . basename($row['receipt_path']);
if (!is_file($path)) {
    json_error('Receipt file is missing on the server.', 404);
}

$downloadName = preg_replace('/[^A-Za-z0-9._ -]/', '_', (string)($row['receipt_name'] ?: 'receipt'));

header('Content-Type: ' . $row['receipt_mime']);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . $downloadName . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');
readfile($path);
exit;
