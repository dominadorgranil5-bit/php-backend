<?php
/**
 * One-time migration: adds the store_settings table behind the landing page's "About" panel
 * (opening/closing time, contact number, email, address, pinned Google Maps link).
 * Admin edits it in Admin Dashboard > Customers > Store Information.
 *
 * Run once:  http://localhost/php-backend/migrate-store-info.php   (or: php migrate-store-info.php)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';

$pdo = db();

$exists = $pdo->prepare(
    'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t'
);
$exists->execute(['t' => 'store_settings']);

if ((int)$exists->fetchColumn() === 0) {
    $pdo->exec(
        "CREATE TABLE store_settings (
          id             TINYINT UNSIGNED NOT NULL PRIMARY KEY,
          open_days      VARCHAR(100) NOT NULL DEFAULT 'Monday-Sunday',
          open_time      TIME         NOT NULL DEFAULT '11:00:00',
          close_time     TIME         NOT NULL DEFAULT '20:00:00',
          contact_number VARCHAR(30)  NOT NULL DEFAULT '',
          email          VARCHAR(150) NOT NULL DEFAULT '',
          address        VARCHAR(255) NOT NULL DEFAULT '',
          maps_url       VARCHAR(500) NOT NULL DEFAULT '',
          updated_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    echo "Created store_settings table.\n";
} else {
    echo "store_settings already exists.\n";
}

// Single row (id = 1). Seeded with the hours the landing page used to hard-code.
$pdo->exec("INSERT IGNORE INTO store_settings (id) VALUES (1)");

echo "Done.\n";
