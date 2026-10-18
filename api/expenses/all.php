<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/expenses_helpers.php';
require_method('GET');
require_role(['staff', 'admin']);

$stmt = db()->query(
    'SELECT e.id, e.category, e.description, e.amount, e.expense_date, e.created_at,
            e.receipt_name, e.receipt_mime,
            u.name AS recorded_by_name
     FROM expenses e
     JOIN users u ON u.id = e.recorded_by
     ORDER BY e.expense_date DESC, e.created_at DESC
     LIMIT 200'
);

json_out([
    'expenses' => $stmt->fetchAll(),
    'totals' => expense_period_totals(),
]);
