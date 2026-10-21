<?php
/**
 * One-time migration: widen drifted columns so signup verification works.
 *
 * The live database drifted from schema.sql (same class of bug as the
 * image_url VARCHAR(80) truncation):
 *   - email_otps.code_hash was varchar(50), but bcrypt hashes are 60 chars.
 *     MySQL (non-strict sql_mode) SILENTLY truncated every stored hash, so
 *     password_verify() could never match and every correct OTP was rejected
 *     as "Incorrect code".
 *   - users.email / users.name and email_otps.pending_* were also narrower
 *     than schema.sql, silently truncating long values.
 *
 * Only ever WIDENS a column (never shrinks), and only when it is currently
 * too small — safe to re-run.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';

$pdo = db();

/** @return array<string,array{type:string,nullable:bool}> column => [type, is nullable], for one table */
function column_types(PDO $pdo, string $table): array
{
    $stmt = $pdo->prepare(
        'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
    );
    $stmt->execute(['t' => $table]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[$row['COLUMN_NAME']] = [
            'type'     => $row['COLUMN_TYPE'],
            'nullable' => $row['IS_NULLABLE'] === 'YES',
        ];
    }
    return $out;
}

/** Extracts the length from e.g. "varchar(50)" — 0 for non-sized types. */
function type_length(string $type): int
{
    return preg_match('/\((\d+)\)/', $type, $m) ? (int)$m[1] : 0;
}

/**
 * @param array<string,array{int,bool}> $columns column => [wanted length, require NOT NULL]
 */
$targets = [
    'email_otps' => [
        'code_hash'             => [255, true], // THE bug: 60-char bcrypt hash truncated at 50
        'pending_name'          => [150, false],
        'pending_phone'         => [30, false],
        'pending_password_hash' => [255, false],
    ],
    'users' => [
        'name'  => [150, true], // long full names were truncated
        'email' => [190, true], // emails > 50 chars were truncated -> login would break
    ],
];

$changed = 0;
foreach ($targets as $table => $columns) {
    $current = column_types($pdo, $table);
    if (!$current) {
        echo "SKIP: table `$table` does not exist.\n";
        continue;
    }
    foreach ($columns as $column => [$wanted, $requireNotNull]) {
        if (!isset($current[$column])) {
            echo "SKIP: `$table`.`$column` does not exist.\n";
            continue;
        }
        $type = $current[$column]['type'];
        if (type_length($type) >= $wanted) {
            echo "ok:   `$table`.`$column` is $type (>= $wanted), untouched.\n";
            continue;
        }
        // Nullability: keep the live column's, unless the target explicitly requires NOT NULL
        // (and the live column is currently nullable-but-shouldn't-be per schema.sql).
        $null = $requireNotNull ? 'NOT NULL' : ($current[$column]['nullable'] ? 'NULL' : 'NOT NULL');
        $pdo->exec("ALTER TABLE `$table` MODIFY `$column` VARCHAR($wanted) $null");
        echo "FIXED: `$table`.`$column` $type -> varchar($wanted) $null.\n";
        $changed++;
    }
}

echo $changed === 0
    ? "Already up to date — nothing to change.\n"
    : "Done — widened $changed column(s). Existing truncated rows are unaffected;\n"
      . "pending codes simply need to be re-requested (a new code is stored fresh).\n";
