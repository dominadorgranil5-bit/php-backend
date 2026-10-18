<?php
/** Shared date and aggregate helpers for expense tracking. */

function expense_date_range(string $startParam, string $endParam): array
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startParam) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endParam)) {
        json_error('start and end query parameters are required as YYYY-MM-DD.', 400);
    }

    try {
        $start = new DateTime($startParam . ' 00:00:00');
        $end = new DateTime($endParam . ' 23:59:59');
    } catch (Exception $e) {
        json_error('Invalid date.', 400);
    }

    if ($start > $end) {
        json_error('start date must be on or before the end date.', 400);
    }

    return [$start, $end];
}

function expense_total(DateTime $start, DateTime $end): float
{
    $stmt = db()->prepare(
        'SELECT COALESCE(SUM(amount), 0) FROM expenses
         WHERE expense_date BETWEEN :start_date AND :end_date'
    );
    $stmt->execute([
        'start_date' => $start->format('Y-m-d'),
        'end_date' => $end->format('Y-m-d'),
    ]);
    return (float)$stmt->fetchColumn();
}

function expense_period_totals(): array
{
    $now = new DateTime();
    $startOfDay = (clone $now)->setTime(0, 0, 0);
    $startOfWeek = (clone $startOfDay)->modify('-' . (int)$startOfDay->format('w') . ' days');
    $startOfMonth = (clone $now)->modify('first day of this month')->setTime(0, 0, 0);

    return [
        'today' => expense_total($startOfDay, $now),
        'thisWeek' => expense_total($startOfWeek, $now),
        'thisMonth' => expense_total($startOfMonth, $now),
    ];
}

// ---------------------------------------------------------------------
// Receipt uploads
// ---------------------------------------------------------------------

const EXPENSE_RECEIPT_MAX_BYTES = 5 * 1024 * 1024; // 5 MB
const EXPENSE_RECEIPT_TYPES = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'application/pdf' => 'pdf',
];

function expense_receipt_dir(): string
{
    $dir = __DIR__ . '/../uploads/expense-receipts';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    // Receipts are private: never served directly, only through
    // api/expenses/receipt.php (which checks the login token).
    $htaccess = $dir . '/.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents(
            $htaccess,
            "Require all denied\n<IfModule !mod_authz_core.c>\n  Order deny,allow\n  Deny from all\n</IfModule>\n"
        );
    }
    return $dir;
}

/**
 * Validates an uploaded receipt ($_FILES entry) and moves it into private
 * storage. Sends a JSON 4xx and exits on any problem, so callers only get a
 * result back for a good file.
 *
 * Returns ['path' => stored filename, 'name' => original name, 'mime' => detected type].
 */
function save_expense_receipt(array $file): array
{
    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        json_error('Receipt is too large for the server. Keep it at 5MB or smaller.', 413);
    }
    if ($error !== UPLOAD_ERR_OK) {
        json_error('The receipt upload failed. Please try again.', 400);
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        json_error('Invalid upload.', 400);
    }
    if ($file['size'] <= 0) {
        json_error('The receipt file is empty.', 400);
    }
    if ($file['size'] > EXPENSE_RECEIPT_MAX_BYTES) {
        json_error('Receipt must be 5MB or smaller.', 413);
    }

    // Trust the file's real contents, not the browser-supplied type or name.
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!isset(EXPENSE_RECEIPT_TYPES[$mime])) {
        json_error('Receipt must be a JPEG, PNG, WEBP image or a PDF.', 415);
    }

    $filename = bin2hex(random_bytes(16)) . '.' . EXPENSE_RECEIPT_TYPES[$mime];
    if (!move_uploaded_file($file['tmp_name'], expense_receipt_dir() . '/' . $filename)) {
        json_error('Could not save the receipt file.', 500);
    }

    // Original name is display-only: strip path parts / control chars, cap length.
    $name = basename(str_replace('\\', '/', (string)($file['name'] ?? 'receipt')));
    $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name);
    $name = mb_substr($name !== '' ? $name : 'receipt', 0, 150);

    return ['path' => $filename, 'name' => $name, 'mime' => $mime];
}
