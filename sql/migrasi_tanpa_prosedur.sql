-- =========================================================
-- ALTERNATIF MIGRASI TANPA CREATE PROCEDURE
-- Untuk hosting yang tidak mengizinkan CREATE ROUTINE.
-- Jalankan HANYA baris untuk kolom/tabel yang belum ada.
-- (Cek dulu lewat health.php)
-- =========================================================
USE `bumk_umgo`;

-- --- Status bayar Qmas (dari lunas → tunai/transfer/piutang) ---
ALTER TABLE `qmas_transactions` MODIFY `payment_status` ENUM('lunas','tunai','transfer','piutang') NOT NULL DEFAULT 'lunas';
UPDATE `qmas_transactions` SET `payment_status`='tunai' WHERE `payment_status`='lunas';
ALTER TABLE `qmas_transactions` MODIFY `payment_status` ENUM('tunai','transfer','piutang') NOT NULL DEFAULT 'tunai';

-- --- Kolom yang mungkin belum ada (lewati jika sudah ada) ---
ALTER TABLE `qmas_transactions` ADD COLUMN `bank` ENUM('BSI','MUAMALAT') NULL DEFAULT NULL AFTER `payment_status`;
ALTER TABLE `qmas_transactions` ADD COLUMN `lunas_date` DATETIME NULL AFTER `bank`;
ALTER TABLE `qmas_transactions` ADD COLUMN `setor_date` DATETIME NULL AFTER `lunas_date`;
ALTER TABLE `qmas_transactions` ADD COLUMN `import_batch` VARCHAR(80) NULL AFTER `user_id`;
ALTER TABLE `qmas_transaction_items` ADD COLUMN `unit_cost` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `unit_price`;
ALTER TABLE `qmas_products` ADD COLUMN `cost` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `price`;
ALTER TABLE `qmas_products` ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `unit`;

-- --- Harga/HPP sesuai sheet ---
UPDATE `qmas_products` SET `price`=23000, `cost`=17000 WHERE `sku`='QMS-G19';
UPDATE `qmas_products` SET `price`=24000, `cost`=19000 WHERE `sku`='QMS-240';
UPDATE `qmas_products` SET `price`=52000, `cost`=44000 WHERE `sku`='QMS-330';
UPDATE `qmas_products` SET `price`=60000, `cost`=54000 WHERE `sku`='QMS-B60';
UPDATE `qmas_products` SET `price`=70000, `cost`=61000 WHERE `sku`='QMS-B15';
UPDATE `qmas_products` SET `is_active`=1 WHERE `is_active` IS NULL;

-- --- Tabel pembelian Qmas & SM Corner ---
CREATE TABLE IF NOT EXISTS `qmas_purchases` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `purchase_no` VARCHAR(40) NOT NULL UNIQUE,
  `purchase_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `invoice_no` VARCHAR(60) NULL,
  `supplier_name` VARCHAR(120) NOT NULL,
  `payment_status` ENUM('tunai','transfer','piutang') NOT NULL DEFAULT 'tunai',
  `bank` ENUM('BSI','MUAMALAT') NULL DEFAULT NULL,
  `total_amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `notes` VARCHAR(255) NULL,
  `user_id` INT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `qmas_purchase_items` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `purchase_id` INT NOT NULL,
  `product_id` INT NOT NULL,
  `quantity` INT NOT NULL,
  `unit_price` DECIMAL(12,2) NOT NULL,
  `subtotal` DECIMAL(12,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `sm_purchases` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `purchase_no` VARCHAR(40) NOT NULL UNIQUE,
  `purchase_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `invoice_no` VARCHAR(60) NULL,
  `supplier_name` VARCHAR(120) NOT NULL,
  `payment_status` ENUM('tunai','transfer','piutang') NOT NULL DEFAULT 'tunai',
  `bank` ENUM('BSI','MUAMALAT') NULL DEFAULT NULL,
  `total_amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `notes` VARCHAR(255) NULL,
  `user_id` INT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `sm_purchase_items` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `purchase_id` INT NOT NULL,
  `product_id` INT NOT NULL,
  `quantity` INT NOT NULL,
  `unit_price` DECIMAL(12,2) NOT NULL,
  `subtotal` DECIMAL(12,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --- SM Corner ---
ALTER TABLE `sm_products` ADD COLUMN `cost` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `price`;
ALTER TABLE `sm_transaction_items` ADD COLUMN `unit_cost` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `unit_price`;

CREATE TABLE IF NOT EXISTS `sm_catalog_summary` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `import_batch` VARCHAR(80) NOT NULL,
  `export_date` VARCHAR(60) NULL,
  `category_name` VARCHAR(100) NOT NULL,
  `product_count` INT NOT NULL DEFAULT 0,
  `variant_count` INT NOT NULL DEFAULT 0,
  `stock_total` INT NOT NULL DEFAULT 0,
  `inventory_value` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `avg_price` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `is_total` TINYINT(1) NOT NULL DEFAULT 0,
  `imported_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_batch` (`import_batch`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
