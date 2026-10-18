<?php
/**
 * One-time migration: overtime request columns on attendance (ot_requested_at, ot_status, ot_decided_at).
 * Safe to run more than once. Run AFTER migrate-attendance.php.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';

$pdo = db();

function attendance_has_column(PDO $pdo, string $column): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND COLUMN_NAME = :c"
    );
    $stmt->execute(['c' => $column]);
    return (int)$stmt->fetchColumn() > 0;
}

if (!attendance_has_column($pdo, 'ot_requested_at')) {
    $pdo->exec('ALTER TABLE attendance ADD COLUMN ot_requested_at DATETIME NULL AFTER time_out');
}
if (!attendance_has_column($pdo, 'ot_status')) {
    $pdo->exec("ALTER TABLE attendance ADD COLUMN ot_status VARCHAR(10) NULL AFTER ot_requested_at");
}
if (!attendance_has_column($pdo, 'ot_decided_at')) {
    $pdo->exec('ALTER TABLE attendance ADD COLUMN ot_decided_at DATETIME NULL AFTER ot_status');
}
// Requests made before approval existed are treated as pending.
$pdo->exec("UPDATE attendance SET ot_status = 'pending' WHERE ot_requested_at IS NOT NULL AND ot_status IS NULL");

echo "Done - overtime request columns are ready.\n";
