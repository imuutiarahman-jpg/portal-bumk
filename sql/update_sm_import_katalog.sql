-- =========================================================
-- Tabel ringkasan katalog/inventori SM Corner (dari sheet)
-- =========================================================
USE `bumk_umgo`;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SHOW TABLES LIKE 'sm_catalog_summary';
