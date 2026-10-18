<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_method('POST');

$user = require_role(['staff', 'admin']);
require_once __DIR__ . '/../../lib/expenses_helpers.php';

// Expects multipart/form-data: the expense fields plus a required "receipt" file.
$isMultipart = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data') === 0;
if ($isMultipart && empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    // PHP drops the whole body when it exceeds post_max_size.
    json_error('Upload is too large for the server. Keep the receipt at 5MB or smaller.', 413);
}
$body = $isMultipart ? $_POST : json_body();
$category = require_field($body, 'category');
$amount = (float)($body['amount'] ?? 0);
$description = trim((string)($body['description'] ?? ''));
$expenseDate = trim((string)($body['expense_date'] ?? date('Y-m-d')));

if ($amount <= 0 || !is_finite($amount)) {
    json_error('Amount must be greater than zero.', 400);
}
if (strlen($category) > 80) {
    json_error('Category is too long.', 400);
}
if (strlen($description) > 255) {
    json_error('Description is too long.', 400);
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expenseDate)) {
    json_error('Expense date must be YYYY-MM-DD.', 400);
}

$date = DateTime::createFromFormat('!Y-m-d', $expenseDate);
if (!$date || $date->format('Y-m-d') !== $expenseDate) {
    json_error('Invalid expense date.', 400);
}

// A photo/receipt is required. Validate + store it before inserting, so a bad file rejects the whole request.
if (empty($_FILES['receipt']) || ($_FILES['receipt']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    json_error('A photo or receipt is required.', 400);
}
$receipt = save_expense_receipt($_FILES['receipt']);

$id = make_uuid();
try {
    $stmt = db()->prepare(
        'INSERT INTO expenses (id, recorded_by, category, description, amount, expense_date,
                               receipt_path, receipt_name, receipt_mime)
         VALUES (:id, :recorded_by, :category, :description, :amount, :expense_date,
                 :receipt_path, :receipt_name, :receipt_mime)'
    );
    $stmt->execute([
        'id' => $id,
        'recorded_by' => $user['id'],
        'category' => $category,
        'description' => $description !== '' ? $description : null,
        'amount' => number_format($amount, 2, '.', ''),
        'expense_date' => $expenseDate,
        'receipt_path' => $receipt['path'] ?? null,
        'receipt_name' => $receipt['name'] ?? null,
        'receipt_mime' => $receipt['mime'] ?? null,
    ]);
} catch (Throwable $e) {
    // Don't leave an orphaned file behind if the row could not be saved.
    if ($receipt) {
        @unlink(expense_receipt_dir() . '/' . $receipt['path']);
    }
    throw $e;
}

json_out([
    'id' => $id,
    'recorded_by' => $user['id'],
    'recorded_by_name' => $user['name'],
    'category' => $category,
    'description' => $description !== '' ? $description : null,
    'amount' => $amount,
    'expense_date' => $expenseDate,
    'receipt_name' => $receipt['name'] ?? null,
    'receipt_mime' => $receipt['mime'] ?? null,
]);
