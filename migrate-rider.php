<?php
/**
 * One-time migration for the Rider App. Safe to re-run.
 *  - users.role        += 'rider'
 *  - orders.status     += 'picked_up', 'out_for_delivery', 'delivered'
 *  - email_otps.purpose += 'login'   (rider "Send OTP" sign-in)
 *  - orders: rider_id, delivery_fee, distance_km, rider_accepted_at, picked_up_at, delivered_at
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';

$pdo = db();

function col_info(PDO $pdo, string $table, string $col): ?array
{
    // information_schema (not SHOW COLUMNS ... LIKE): MariaDB can't bind parameters in SHOW
    // statements when native prepares are on, which lib/db.php uses.
    $s = $pdo->prepare(
        'SELECT COLUMN_TYPE AS `Type` FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c'
    );
    $s->execute(['t' => $table, 'c' => $col]);
    return $s->fetch() ?: null;
}

function ensure_enum(PDO $pdo, string $table, string $col, array $add, string $tail): void
{
    $c = col_info($pdo, $table, $col);
    if (!$c) { echo "Skipped — $table.$col not found.\n"; return; }
    if (stripos($c['Type'], 'enum') !== 0) { echo "OK — $table.$col is not an ENUM, nothing to change.\n"; return; }
    preg_match_all("/'((?:[^']|'')*)'/", $c['Type'], $m);
    $vals = array_map(fn($v) => str_replace("''", "'", $v), $m[1]);
    $missing = array_values(array_diff($add, $vals));
    if (!$missing) { echo "Already up to date — $table.$col.\n"; return; }
    $list = implode(',', array_map(fn($v) => $pdo->quote($v), array_merge($vals, $missing)));
    $pdo->exec("ALTER TABLE `$table` MODIFY COLUMN `$col` ENUM($list) $tail");
    echo "Done — $table.$col now accepts: " . implode(', ', $missing) . "\n";
}

ensure_enum($pdo, 'users', 'role', ['rider'], "NOT NULL DEFAULT 'customer'");
ensure_enum($pdo, 'orders', 'status', ['picked_up', 'out_for_delivery', 'delivered'], "NOT NULL DEFAULT 'pending'");
ensure_enum($pdo, 'email_otps', 'purpose', ['login'], 'NOT NULL');

$cols = [
    'rider_id'          => 'CHAR(36) NULL',
    'delivery_fee'      => 'DECIMAL(10,2) NULL',
    'distance_km'       => 'DECIMAL(6,2) NULL',
    'rider_accepted_at' => 'DATETIME NULL',
    'picked_up_at'      => 'DATETIME NULL',
    'delivered_at'      => 'DATETIME NULL',
];
foreach ($cols as $name => $def) {
    if (col_info($pdo, 'orders', $name)) { echo "Already up to date — orders.$name.\n"; continue; }
    $pdo->exec("ALTER TABLE orders ADD COLUMN `$name` $def");
    echo "Done — added orders.$name.\n";
}

$idx = $pdo->query("SHOW INDEX FROM orders WHERE Key_name = 'idx_orders_rider'")->fetch();
if (!$idx) {
    $pdo->exec('ALTER TABLE orders ADD INDEX idx_orders_rider (rider_id, status)');
    echo "Done — added index idx_orders_rider.\n";
}
echo "Rider migration complete.\n";
