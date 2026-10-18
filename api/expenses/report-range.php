<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/expenses_helpers.php';
require_method('GET');
require_role(['staff', 'admin']);

// Expense report for ?start=YYYY-MM-DD to ?end=YYYY-MM-DD (inclusive, by expense date).
// Used by Admin > Expenses > "Generate Report".
[$start, $end] = expense_date_range(
    trim((string)($_GET['start'] ?? '')),
    trim((string)($_GET['end'] ?? ''))
);

// Guard against absurdly large ranges.
if ($start->diff($end)->days > 366) {
    json_error('Date range is too large. Please pick 366 days or fewer.', 400);
}

$params = [
    'start_date' => $start->format('Y-m-d'),
    'end_date' => $end->format('Y-m-d'),
];

$stmt = db()->prepare(
    'SELECT e.id, e.category, e.description, e.amount, e.expense_date, u.name AS recorded_by_name
     FROM expenses e
     JOIN users u ON u.id = e.recorded_by
     WHERE e.expense_date BETWEEN :start_date AND :end_date
     ORDER BY e.expense_date ASC, e.created_at ASC'
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$total = 0.0;
$byCategory = [];
$byDate = [];
$expenses = [];

foreach ($rows as $row) {
    $amount = (float)$row['amount'];
    $total += $amount;

    $cat = (string)$row['category'];
    if (!isset($byCategory[$cat])) {
        $byCategory[$cat] = ['category' => $cat, 'count' => 0, 'total' => 0.0];
    }
    $byCategory[$cat]['count']++;
    $byCategory[$cat]['total'] += $amount;

    $date = (string)$row['expense_date'];
    if (!isset($byDate[$date])) {
        $byDate[$date] = ['date' => $date, 'count' => 0, 'total' => 0.0];
    }
    $byDate[$date]['count']++;
    $byDate[$date]['total'] += $amount;

    $expenses[] = [
        'id' => $row['id'],
        'category' => $cat,
        'description' => $row['description'],
        'amount' => $amount,
        'expense_date' => $date,
        'recorded_by_name' => $row['recorded_by_name'],
    ];
}

// Biggest category first.
$byCategory = array_values($byCategory);
usort($byCategory, fn($a, $b) => $b['total'] <=> $a['total']);

$count = count($expenses);

json_out([
    'start' => trim((string)($_GET['start'] ?? '')),
    'end' => trim((string)($_GET['end'] ?? '')),
    'generatedAt' => date('Y-m-d H:i:s'),
    'total' => $total,
    'count' => $count,
    'average' => $count > 0 ? $total / $count : 0,
    'byCategory' => $byCategory,
    'daily' => array_values($byDate),
    'expenses' => $expenses,
]);
