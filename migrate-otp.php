<?php
/** One-time migration: adds the email_otps table (signup + forgot-password OTP). */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';

$pdo = db();
$exists = $pdo->prepare(
    "SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = 'email_otps'"
);
$exists->execute();

if ((int)$exists->fetchColumn() > 0) {
    echo "Already up to date — email_otps table exists.\n";
    exit;
}

$pdo->exec(
    "CREATE TABLE email_otps (
      id                    CHAR(36)     NOT NULL PRIMARY KEY,
      email                 VARCHAR(190) NOT NULL,
      purpose               ENUM('register','reset') NOT NULL,
      code_hash             VARCHAR(255) NOT NULL,
      pending_name          VARCHAR(150) NULL,
      pending_phone         VARCHAR(30)  NULL,
      pending_password_hash VARCHAR(255) NULL,
      attempts              TINYINT UNSIGNED NOT NULL DEFAULT 0,
      send_count            TINYINT UNSIGNED NOT NULL DEFAULT 1,
      window_start          DATETIME     NOT NULL,
      last_sent_at          DATETIME     NOT NULL,
      expires_at            DATETIME     NOT NULL,
      UNIQUE KEY uq_otp_email_purpose (email, purpose),
      KEY idx_otp_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

echo "Done — created email_otps table.\n";
