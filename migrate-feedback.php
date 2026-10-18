<?php
/** One-time migration: adds the customer_feedback table (messages/ratings customers send to the shop). */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';

$pdo = db();
$exists = $pdo->prepare(
    "SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = 'customer_feedback'"
);
$exists->execute();

if ((int)$exists->fetchColumn() > 0) {
    echo "Already up to date — customer_feedback table exists.\n";
    exit;
}

// user_id has no foreign key on purpose: feedback is kept even if the account is later removed,
// and it works whatever type users.id is. customer_name / customer_email are saved as they were.
$pdo->exec(
    "CREATE TABLE customer_feedback (
      id             INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
      user_id        VARCHAR(36)  NULL,
      customer_name  VARCHAR(150) NOT NULL DEFAULT '',
      customer_email VARCHAR(190) NOT NULL DEFAULT '',
      rating         TINYINT      NULL,
      message        TEXT         NOT NULL,
      created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_feedback_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

echo "Done — created customer_feedback table.\n";
