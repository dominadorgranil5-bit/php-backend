<?php
/**
 * One-time migration: adds the delivery-area tables the admin manages
 * (Admin Dashboard > Customers > Delivery Addresses).
 *   service_cities    = Municipality (+ its province and postal code)
 *   service_barangays = Barangays that belong to a Municipality
 * Customers can only pick from these when saving an address.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';

$pdo = db();

function table_exists(PDO $pdo, string $name): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t'
    );
    $stmt->execute(['t' => $name]);
    return (int)$stmt->fetchColumn() > 0;
}

if (!table_exists($pdo, 'service_cities')) {
    $pdo->exec(
        "CREATE TABLE service_cities (
          id          CHAR(36)     NOT NULL PRIMARY KEY,
          name        VARCHAR(100) NOT NULL,
          province    VARCHAR(100) NOT NULL,
          postal_code CHAR(4)      NOT NULL,
          is_active   TINYINT(1)   NOT NULL DEFAULT 1,
          created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uq_service_city_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    echo "Created service_cities table.\n";
} else {
    echo "service_cities already exists.\n";
}

if (!table_exists($pdo, 'service_barangays')) {
    $pdo->exec(
        "CREATE TABLE service_barangays (
          id         CHAR(36)     NOT NULL PRIMARY KEY,
          city_id    CHAR(36)     NOT NULL,
          name       VARCHAR(100) NOT NULL,
          is_active  TINYINT(1)   NOT NULL DEFAULT 1,
          created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uq_service_barangay (city_id, name),
          CONSTRAINT fk_service_brgy_city FOREIGN KEY (city_id) REFERENCES service_cities(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    echo "Created service_barangays table.\n";
} else {
    echo "service_barangays already exists.\n";
}

echo "Done.\n";
