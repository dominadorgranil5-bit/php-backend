<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/expenses_helpers.php';
require_method('GET');
require_role(['staff', 'admin']);

// Total expenses (by expense date) between ?start=YYYY-MM-DD and ?end=YYYY-MM-DD, inclusive.
// Used by Admin > Reports to show "Total Expenses" for the selected period.
[$start, $end] = expense_date_range(
    trim((string)($_GET['start'] ?? '')),
    trim((string)($_GET['end'] ?? ''))
);

json_out(['total' => expense_total($start, $end)]);
