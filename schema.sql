-- =====================================================================
-- Drew's Cafe — MySQL schema (replaces Supabase/Postgres)
-- Import this first:  mysql -u root -p your_db < schema.sql
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- users  (replaces Supabase auth.users + public.profiles, merged in one)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id            CHAR(36)      NOT NULL PRIMARY KEY,
  name          VARCHAR(150)  NOT NULL,
  email         VARCHAR(190)  NOT NULL,
  phone         VARCHAR(30)   NOT NULL DEFAULT '',
  password_hash VARCHAR(255)  NOT NULL,
  role          ENUM('customer','staff','admin','rider') NOT NULL DEFAULT 'customer',
  is_active     TINYINT(1)    NOT NULL DEFAULT 1,
  created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- sessions  (opaque bearer tokens — replaces Supabase JWT session)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sessions (
  token       CHAR(64)   NOT NULL PRIMARY KEY,
  user_id     CHAR(36)   NOT NULL,
  expires_at  DATETIME   NOT NULL,
  created_at  DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sessions_user (user_id),
  CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- menu_items
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS menu_items (
  id          CHAR(36)      NOT NULL PRIMARY KEY,
  name        VARCHAR(150)  NOT NULL,
  description TEXT          NULL,
  price       DECIMAL(10,2) NOT NULL DEFAULT 0,
  category    VARCHAR(80)   NOT NULL DEFAULT '',
  emoji       VARCHAR(10)   NOT NULL DEFAULT '',
  image_url   VARCHAR(500)  NULL,
  available   TINYINT(1)    NOT NULL DEFAULT 1,
  created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_menu_available (available),
  KEY idx_menu_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- menu_option_groups  (e.g. Size / Flavor / Add-ons for a pizza)
-- selection_type 'single' = pick exactly one (radio), 'multiple' = pick any
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS menu_option_groups (
  id            CHAR(36)     NOT NULL PRIMARY KEY,
  menu_item_id  CHAR(36)     NOT NULL,
  name          VARCHAR(100) NOT NULL,
  selection_type ENUM('single','multiple') NOT NULL DEFAULT 'single',
  required      TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order    INT          NOT NULL DEFAULT 0,
  KEY idx_mog_item (menu_item_id),
  CONSTRAINT fk_mog_item FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- menu_options  (each choice inside a group, e.g. Large +100)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS menu_options (
  id          CHAR(36)      NOT NULL PRIMARY KEY,
  group_id    CHAR(36)      NOT NULL,
  name        VARCHAR(100)  NOT NULL,
  description VARCHAR(255)  NULL,
  extra_price DECIMAL(10,2) NOT NULL DEFAULT 0,
  available   TINYINT(1)    NOT NULL DEFAULT 1,
  sort_order  INT           NOT NULL DEFAULT 0,
  KEY idx_mo_group (group_id),
  CONSTRAINT fk_mo_group FOREIGN KEY (group_id) REFERENCES menu_option_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- expenses  (staff/admin operating expense records)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS expenses (
  id           CHAR(36)      NOT NULL PRIMARY KEY,
  recorded_by  CHAR(36)      NOT NULL,
  category     VARCHAR(80)   NOT NULL,
  description  VARCHAR(255)  NULL,
  amount       DECIMAL(10,2) NOT NULL,
  expense_date DATE          NOT NULL,
  receipt_path VARCHAR(255)  NULL,
  receipt_name VARCHAR(150)  NULL,
  receipt_mime VARCHAR(100)  NULL,
  created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_expenses_date (expense_date),
  KEY idx_expenses_recorded_by (recorded_by),
  CONSTRAINT fk_expenses_recorded_by FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- order_item_options  (snapshot of what the customer picked, so old
-- orders stay correct even if the menu changes later)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS order_item_options (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  order_item_id  INT           NOT NULL,
  group_name     VARCHAR(100)  NOT NULL DEFAULT '',
  option_name    VARCHAR(100)  NOT NULL,
  extra_price    DECIMAL(10,2) NOT NULL DEFAULT 0,
  KEY idx_oio_item (order_item_id),
  CONSTRAINT fk_oio_item FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- orders
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS orders (
  id                  CHAR(36)      NOT NULL PRIMARY KEY,
  customer_id         CHAR(36)      NOT NULL,
  status              ENUM('pending','preparing','ready','completed','cancelled','picked_up','out_for_delivery','delivered') NOT NULL DEFAULT 'pending',
  order_type          ENUM('pickup','delivery','dinein') NOT NULL DEFAULT 'delivery',
  address             VARCHAR(500)  NOT NULL DEFAULT '',
  notes               TEXT          NULL,
  total               DECIMAL(10,2) NOT NULL DEFAULT 0,
  payment_method      ENUM('cash','gcash') NOT NULL DEFAULT 'cash',
  payment_reference   VARCHAR(190)  NULL,
  cash_received       DECIMAL(10,2) NULL,
  payment_status      ENUM('unpaid','paid') NOT NULL DEFAULT 'unpaid',
  order_source        ENUM('online','pos') NOT NULL DEFAULT 'online',
  contact_number      VARCHAR(30)   NOT NULL DEFAULT '',
  customer_name       VARCHAR(100)  NOT NULL DEFAULT '',
  rider_id            CHAR(36)      NULL,
  delivery_fee        DECIMAL(10,2) NULL,
  distance_km         DECIMAL(6,2)  NULL,
  rider_accepted_at   DATETIME      NULL,
  picked_up_at        DATETIME      NULL,
  delivered_at        DATETIME      NULL,
  receipt_requested_at DATETIME     NULL,
  receipt_printed_at  DATETIME     NULL,
  paymongo_source_id  VARCHAR(190)  NULL,
  paymongo_payment_intent_id VARCHAR(190) NULL,
  created_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_orders_customer (customer_id),
  KEY idx_orders_created (created_at),
  KEY idx_orders_updated (updated_at),
  KEY idx_orders_source (paymongo_source_id),
  KEY idx_orders_intent (paymongo_payment_intent_id),
  KEY idx_orders_rider (rider_id, status),
  CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- order_items
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS order_items (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  order_id    CHAR(36)      NOT NULL,
  product_id  CHAR(36)      NOT NULL,
  name        VARCHAR(150)  NOT NULL,
  price       DECIMAL(10,2) NOT NULL DEFAULT 0,
  quantity    INT           NOT NULL DEFAULT 1,
  KEY idx_order_items_order (order_id),
  CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- void_numbers  (admin-issued codes staff must enter to authorize an
-- order cancellation — shared server-side so every device sees the same list)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS void_numbers (
  id          CHAR(36)     NOT NULL PRIMARY KEY,
  code        VARCHAR(50)  NOT NULL,
  created_by  CHAR(36)     NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_void_numbers_code (code),
  KEY idx_void_numbers_created_by (created_by),
  CONSTRAINT fk_void_numbers_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- Migration: if your database already exists (orders table was created
-- before order_type existed), run this once instead of the CREATE TABLE
-- above:
--   ALTER TABLE orders
--     ADD COLUMN order_type ENUM('pickup','delivery','dinein') NOT NULL DEFAULT 'delivery'
--     AFTER status;
-- ---------------------------------------------------------------------

-- ---------------------------------------------------------------------
-- Seed an initial admin account so you can log in and promote others.
-- Email: admin@drewscafe.test   Password: Admin123!
-- (hash below is password_hash('Admin123!', PASSWORD_BCRYPT) — change it
-- after first login)
-- ---------------------------------------------------------------------
INSERT INTO users (id, name, email, phone, password_hash, role)
VALUES (
  UUID(),
  'Admin',
  'admin@drewscafe.test',
  '',
  '$2y$10$vmqrEYnMHgxNRxvIVzGu0O7xkEyBwdGfCqAsjVz978FCOZKVDDFkq',
  'admin'
)
ON DUPLICATE KEY UPDATE email = email;

-- ---------------------------------------------------------------------
-- email_otps  (6-digit email codes: signup verification + password reset)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS email_otps (
  id                    CHAR(36)     NOT NULL PRIMARY KEY,
  email                 VARCHAR(190) NOT NULL,
  purpose               ENUM('register','reset','login') NOT NULL,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
