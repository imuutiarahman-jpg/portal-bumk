-- =========================================================
-- Lengkapi SM Corner: HPP produk + pembelian/stok masuk
-- Aman dijalankan berulang sebagian (kolom dicek dulu).
-- =========================================================
USE `bumk_umgo`;

DROP PROCEDURE IF EXISTS `bumk_add_col`;
DELIMITER $$
CREATE PROCEDURE `bumk_add_col`(IN p_table VARCHAR(64), IN p_column VARCHAR(64), IN p_ddl TEXT)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA='bumk_umgo' AND TABLE_NAME=p_table AND COLUMN_NAME=p_column
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN ', p_ddl);
        PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    END IF;
END $$
DELIMITER ;
CALL `bumk_add_col`('sm_products', 'cost', '`cost` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `price`');
CALL `bumk_add_col`('sm_transaction_items', 'unit_cost', '`unit_cost` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `unit_price`');
DROP PROCEDURE IF EXISTS `bumk_add_col`;

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
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sm_purchase_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `purchase_id` INT NOT NULL,
    `product_id` INT NOT NULL,
    `quantity` INT NOT NULL,
    `unit_price` DECIMAL(12,2) NOT NULL,
    `subtotal` DECIMAL(12,2) NOT NULL,
    FOREIGN KEY (`purchase_id`) REFERENCES `sm_purchases`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`product_id`) REFERENCES `sm_products`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT `id`, `sku`, `name`, `price`, `cost` FROM `sm_products` ORDER BY `id`;
SHOW TABLES LIKE 'sm_purch%';
