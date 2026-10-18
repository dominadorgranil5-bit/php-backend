<?php
/** One-time migration: creates the staff attendance ("pasok") table. Safe to run more than once. */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';

db()->exec(
    "CREATE TABLE IF NOT EXISTS attendance (
       id         INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
       user_id    CHAR(36)      NOT NULL,
       work_date  DATE          NOT NULL,
       first_in   DATETIME      NOT NULL,
       last_seen  DATETIME      NOT NULL,
       time_out   DATETIME      NULL,
       UNIQUE KEY uq_attendance_user_day (user_id, work_date),
       KEY idx_attendance_date (work_date),
       CONSTRAINT fk_attendance_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

echo "Done - attendance table is ready.\n";
