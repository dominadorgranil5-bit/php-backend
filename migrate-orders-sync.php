<?php
/**
 * One-time migration: staff/rider/customer order sync.
 * Safe to re-run.
 *  - orders.order_source   += 'online' | 'pos'   (single source of truth for where an
 *                                                 order came from; replaces the old
 *                                                 payment_reference = 'walk-in-*' hack)
 *  - orders.contact_number  — delivery contact the rider calls (POS: captured at the
 *                             counter; online: snapshot of the account's phone)
 *  - orders.customer_name   — the walk-in customer's real name (a POS order belongs to
 *                             the staff account that rang it up, not to the customer)
 * Backfills: old walk-in rows -> order_source='pos'; delivery orders get the account
 * phone as contact_number so riders can always call.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';

$pdo = db();

function col_info(PDO $pdo, string $table, string $col): ?array
{
    // information_schema (not SHOW COLUMNS ... LIKE): MariaDB can't bind parameters in SHOW
    // statements when native prepares are on, which lib/db.php uses.
    $s = $pdo->prepare(
        'SELECT COLUMN_TYPE AS `Type`, CHARACTER_MAXIMUM_LENGTH AS `len` FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c'
    );
    $s->execute(['t' => $table, 'c' => $col]);
    return $s->fetch() ?: null;
}

$c = col_info($pdo, 'orders', 'order_source');
if (!$c) {
    $pdo->exec("ALTER TABLE orders ADD COLUMN `order_source` ENUM('online','pos') NOT NULL DEFAULT 'online'");
    echo "Done — added orders.order_source.\n";
} else {
    echo "Already up to date — orders.order_source.\n";
}

// Old walk-in rows are only identifiable by the payment_reference marker.
$n = $pdo->exec("UPDATE orders SET order_source = 'pos' WHERE payment_reference LIKE 'walk-in%'");
echo "Backfilled order_source='pos' on $n old walk-in row(s).\n";

foreach (['contact_number' => "VARCHAR(30) NOT NULL DEFAULT ''", 'customer_name' => "VARCHAR(100) NOT NULL DEFAULT ''"] as $name => $def) {
    if (!col_info($pdo, 'orders', $name)) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN `$name` $def");
        echo "Done — added orders.$name.\n";
    } else {
        echo "Already up to date — orders.$name.\n";
    }
}

// Riders must be able to call the customer on deliveries that already exist.
$n = $pdo->exec(
    "UPDATE orders o JOIN users u ON u.id = o.customer_id
        SET o.contact_number = u.phone
      WHERE o.order_type = 'delivery' AND o.contact_number = '' AND u.phone <> ''"
);
echo "Backfilled contact_number on $n existing delivery row(s).\n";

echo "Orders sync migration complete.\n";
