-- =========================================================
-- Fitur Pembelian / Stok Masuk Qmas
-- =========================================================
USE `bumk_umgo`;

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
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `qmas_purchase_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `purchase_id` INT NOT NULL,
    `product_id` INT NOT NULL,
    `quantity` INT NOT NULL,
    `unit_price` DECIMAL(12,2) NOT NULL,
    `subtotal` DECIMAL(12,2) NOT NULL,
    FOREIGN KEY (`purchase_id`) REFERENCES `qmas_purchases`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`product_id`) REFERENCES `qmas_products`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SHOW TABLES LIKE 'qmas_purch%';
