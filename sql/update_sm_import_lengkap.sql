-- =========================================================
-- Import katalog lengkap SM Corner dari Google Sheets
-- (semua tab: kategori, produk, varian, riwayat, ringkasan)
--
-- Catatan: modulo import juga menjalankan pengecekan skema yang
-- sama secara otomatis (includes/sm_import.php). File ini dipakai
-- jika ingin menyiapkan database manual lewat phpMyAdmin.
-- =========================================================
USE `bumk_umgo`;

CREATE TABLE IF NOT EXISTS `sm_product_variants` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `product_id` INT NOT NULL,
    `import_batch` VARCHAR(80) NULL,
    `source_key` VARCHAR(60) NULL,
    `size_name` VARCHAR(30) NOT NULL DEFAULT '-',
    `color_hex` VARCHAR(20) NULL,
    `stock` INT NOT NULL DEFAULT 0,
    `price` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `min_stock` INT NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_smv_product` (`product_id`),
    INDEX `idx_smv_batch` (`import_batch`),
    FOREIGN KEY (`product_id`) REFERENCES `sm_products`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sm_stock_history` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `import_batch` VARCHAR(80) NULL,
    `source_log_id` VARCHAR(40) NULL,
    `source_product_key` VARCHAR(60) NULL,
    `product_id` INT NULL,
    `log_time` DATETIME NULL,
    `size_name` VARCHAR(30) NULL,
    `change_qty` INT NOT NULL DEFAULT 0,
    `remaining_stock` INT NOT NULL DEFAULT 0,
    `note` VARCHAR(255) NULL,
    `actor` VARCHAR(60) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_smsh_batch` (`import_batch`),
    INDEX `idx_smsh_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sm_import_batches` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `import_batch` VARCHAR(80) NOT NULL UNIQUE,
    `sheet_id` VARCHAR(80) NULL,
    `export_date` VARCHAR(60) NULL,
    `filename` VARCHAR(200) NULL,
    `category_count` INT NOT NULL DEFAULT 0,
    `product_count` INT NOT NULL DEFAULT 0,
    `variant_count` INT NOT NULL DEFAULT 0,
    `summary_count` INT NOT NULL DEFAULT 0,
    `history_count` INT NOT NULL DEFAULT 0,
    `imported_by` INT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tambah kolom hanya jika belum ada (aman dijalankan ulang).
SET @sql := NULL;
SELECT GROUP_CONCAT(CONCAT('ADD COLUMN `', c.col, '` ', c.def) SEPARATOR ', ') INTO @sql
FROM (
    SELECT 'source_key' col, 'VARCHAR(60) NULL' def WHERE NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sm_categories' AND COLUMN_NAME='source_key')
    UNION ALL SELECT 'default_sizes', 'VARCHAR(120) NULL' WHERE NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sm_categories' AND COLUMN_NAME='default_sizes')
    UNION ALL SELECT 'import_batch', 'VARCHAR(80) NULL' WHERE NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sm_categories' AND COLUMN_NAME='import_batch')
) c;
SET @sql := IF(@sql IS NULL, 'SELECT 1', CONCAT('ALTER TABLE `sm_categories` ', @sql));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := NULL;
SELECT GROUP_CONCAT(CONCAT('ADD COLUMN `', c.col, '` ', c.def) SEPARATOR ', ') INTO @sql
FROM (
    SELECT 'source_key' col, 'VARCHAR(60) NULL' def WHERE NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sm_products' AND COLUMN_NAME='source_key')
    UNION ALL SELECT 'import_batch', 'VARCHAR(80) NULL' WHERE NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sm_products' AND COLUMN_NAME='import_batch')
    UNION ALL SELECT 'color', 'VARCHAR(40) NULL' WHERE NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sm_products' AND COLUMN_NAME='color')
    UNION ALL SELECT 'sizes', 'VARCHAR(120) NULL' WHERE NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sm_products' AND COLUMN_NAME='sizes')
    UNION ALL SELECT 'min_price', 'DECIMAL(12,2) NOT NULL DEFAULT 0' WHERE NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sm_products' AND COLUMN_NAME='min_price')
    UNION ALL SELECT 'max_price', 'DECIMAL(12,2) NOT NULL DEFAULT 0' WHERE NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sm_products' AND COLUMN_NAME='max_price')
    UNION ALL SELECT 'source_created_at', 'DATETIME NULL' WHERE NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sm_products' AND COLUMN_NAME='source_created_at')
) c;
SET @sql := IF(@sql IS NULL, 'SELECT 1', CONCAT('ALTER TABLE `sm_products` ', @sql));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Kolom variant_id ditambahkan satu per satu karena namanya sama di dua tabel.
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sm_transaction_items' AND COLUMN_NAME='variant_id'),
    'SELECT 1', 'ALTER TABLE `sm_transaction_items` ADD COLUMN `variant_id` BIGINT NULL');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sm_purchase_items' AND COLUMN_NAME='variant_id'),
    'SELECT 1', 'ALTER TABLE `sm_purchase_items` ADD COLUMN `variant_id` BIGINT NULL');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SHOW TABLES LIKE 'sm_product_variants';
SHOW TABLES LIKE 'sm_stock_history';
SHOW TABLES LIKE 'sm_import_batches';
