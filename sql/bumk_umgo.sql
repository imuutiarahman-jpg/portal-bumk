-- =========================================================
-- Portal Terintegrasi BUMK UMGO
-- Database: bumk_umgo
-- Import lewat phpMyAdmin: buat/ Pilih database -> Import -> file ini
-- =========================================================
CREATE DATABASE IF NOT EXISTS `bumk_umgo` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `bumk_umgo`;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `daqam_shift_closings`;
DROP TABLE IF EXISTS `daqam_order_items`;
DROP TABLE IF EXISTS `daqam_recipes`;
DROP TABLE IF EXISTS `daqam_orders`;
DROP TABLE IF EXISTS `daqam_ingredients`;
DROP TABLE IF EXISTS `daqam_menu`;
DROP TABLE IF EXISTS `daqam_categories`;
DROP TABLE IF EXISTS `sm_purchase_items`;
DROP TABLE IF EXISTS `sm_purchases`;
DROP TABLE IF EXISTS `sm_transaction_items`;
DROP TABLE IF EXISTS `sm_transactions`;
DROP TABLE IF EXISTS `sm_products`;
DROP TABLE IF EXISTS `sm_categories`;
DROP TABLE IF EXISTS `qmas_purchase_items`;
DROP TABLE IF EXISTS `qmas_purchases`;
DROP TABLE IF EXISTS `qmas_gallon_exchanges`;
DROP TABLE IF EXISTS `qmas_transaction_items`;
DROP TABLE IF EXISTS `qmas_transactions`;
DROP TABLE IF EXISTS `qmas_products`;
DROP TABLE IF EXISTS `activity_logs`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `units`;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------
-- 1. UNIT & AKSES
-- ---------------------------------------------------------
CREATE TABLE `units` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(20) NOT NULL UNIQUE,
    `name` VARCHAR(100) NOT NULL,
    `color` VARCHAR(20) NOT NULL,
    `icon` VARCHAR(50) NOT NULL,
    `description` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `unit_id` INT NULL,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `full_name` VARCHAR(100) NOT NULL,
    `role` ENUM('super_admin','admin_unit','kasir') NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `must_change_password` TINYINT(1) NOT NULL DEFAULT 0,
    `failed_attempts` INT NOT NULL DEFAULT 0,
    `locked_until` DATETIME NULL,
    `last_password_changed` DATETIME NULL,
    `last_login` DATETIME NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`unit_id`) REFERENCES `units`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `activity_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NULL,
    `action` VARCHAR(50) NOT NULL,
    `description` VARCHAR(255) NOT NULL,
    `ip_address` VARCHAR(45) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- 2. QMAS AIR MINERAL
-- ---------------------------------------------------------
CREATE TABLE `qmas_products` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(120) NOT NULL,
    `sku` VARCHAR(40) NOT NULL UNIQUE,
    `kind` ENUM('galon','gelas','botol','dus') NOT NULL,
    `size` VARCHAR(30) NOT NULL,
    `price` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `cost` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `stock` INT NOT NULL DEFAULT 0,
    `opening_stock` INT NOT NULL DEFAULT 0,
    `opening_stock_date` DATETIME NULL,
    `min_stock` INT NOT NULL DEFAULT 0,
    `unit` VARCHAR(15) NOT NULL DEFAULT 'pcs',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `qmas_transactions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `transaction_no` VARCHAR(40) NOT NULL UNIQUE,
    `transaction_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `customer_name` VARCHAR(120) NOT NULL,
    `customer_type` ENUM('fakultas','mahasiswa','umum') NOT NULL DEFAULT 'umum',
    `payment_status` ENUM('tunai','transfer','piutang') NOT NULL DEFAULT 'tunai',
    `bank` ENUM('BSI','MUAMALAT') NULL DEFAULT NULL,
    `lunas_date` DATETIME NULL,
    `setor_date` DATETIME NULL,
    `total_amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `user_id` INT NULL,
    `import_batch` VARCHAR(80) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `qmas_transaction_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `transaction_id` INT NOT NULL,
    `product_id` INT NOT NULL,
    `quantity` INT NOT NULL,
    `unit_price` DECIMAL(12,2) NOT NULL,
    `unit_cost` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `subtotal` DECIMAL(12,2) NOT NULL,
    FOREIGN KEY (`transaction_id`) REFERENCES `qmas_transactions`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`product_id`) REFERENCES `qmas_products`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `qmas_purchases` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `purchase_no` VARCHAR(40) NOT NULL UNIQUE,
    `purchase_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `invoice_no` VARCHAR(60) NULL,
    `supplier_name` VARCHAR(120) NOT NULL,
    `payment_status` ENUM('tunai','transfer','piutang') NOT NULL DEFAULT 'tunai',
    `bank` ENUM('BSI','MUAMALAT') NULL DEFAULT NULL,
    `lunas_date` DATETIME NULL,
    `total_amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `notes` VARCHAR(255) NULL,
    `user_id` INT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `qmas_purchase_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `purchase_id` INT NOT NULL,
    `product_id` INT NOT NULL,
    `quantity` INT NOT NULL,
    `unit_price` DECIMAL(12,2) NOT NULL,
    `subtotal` DECIMAL(12,2) NOT NULL,
    FOREIGN KEY (`purchase_id`) REFERENCES `qmas_purchases`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`product_id`) REFERENCES `qmas_products`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `qmas_gallon_exchanges` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `exchange_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `customer_name` VARCHAR(120) NOT NULL,
    `full_out` INT NOT NULL DEFAULT 0,
    `empty_in` INT NOT NULL DEFAULT 0,
    `notes` VARCHAR(255) NULL,
    `user_id` INT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- 3. SUARA MUHAMMADIYAH (SM) CORNER
-- ---------------------------------------------------------
CREATE TABLE `sm_categories` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `description` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sm_products` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `category_id` INT NOT NULL,
    `name` VARCHAR(120) NOT NULL,
    `sku` VARCHAR(40) NOT NULL UNIQUE,
    `size` VARCHAR(15) NOT NULL DEFAULT '-',
    `price` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `cost` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `stock` INT NOT NULL DEFAULT 0,
    `min_stock` INT NOT NULL DEFAULT 0,
    `unit` VARCHAR(15) NOT NULL DEFAULT 'pcs',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`category_id`) REFERENCES `sm_categories`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sm_purchases` (
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

CREATE TABLE `sm_purchase_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `purchase_id` INT NOT NULL,
    `product_id` INT NOT NULL,
    `quantity` INT NOT NULL,
    `unit_price` DECIMAL(12,2) NOT NULL,
    `subtotal` DECIMAL(12,2) NOT NULL,
    FOREIGN KEY (`purchase_id`) REFERENCES `sm_purchases`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`product_id`) REFERENCES `sm_products`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sm_transactions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `transaction_no` VARCHAR(40) NOT NULL UNIQUE,
    `transaction_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `customer_name` VARCHAR(120) NOT NULL DEFAULT 'Umum',
    `payment_method` ENUM('tunai','qris','transfer') NOT NULL DEFAULT 'tunai',
    `total_amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `user_id` INT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sm_transaction_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `transaction_id` INT NOT NULL,
    `product_id` INT NOT NULL,
    `quantity` INT NOT NULL,
    `unit_price` DECIMAL(12,2) NOT NULL,
    `unit_cost` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `subtotal` DECIMAL(12,2) NOT NULL,
    FOREIGN KEY (`transaction_id`) REFERENCES `sm_transactions`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`product_id`) REFERENCES `sm_products`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- 4. DAQAM CAFFE
-- ---------------------------------------------------------
CREATE TABLE `daqam_categories` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `daqam_menu` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `category_id` INT NOT NULL,
    `name` VARCHAR(120) NOT NULL,
    `sku` VARCHAR(40) NOT NULL UNIQUE,
    `price` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `status` ENUM('tersedia','habis') NOT NULL DEFAULT 'tersedia',
    `icon` VARCHAR(20) NOT NULL DEFAULT '🍽️',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`category_id`) REFERENCES `daqam_categories`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `daqam_ingredients` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(120) NOT NULL,
    `unit` VARCHAR(15) NOT NULL DEFAULT 'pcs',
    `stock` DECIMAL(10,2) NOT NULL DEFAULT 0,
    `min_stock` DECIMAL(10,2) NOT NULL DEFAULT 0,
    `last_price` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `daqam_recipes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `menu_id` INT NOT NULL,
    `ingredient_id` INT NOT NULL,
    `quantity` DECIMAL(10,2) NOT NULL DEFAULT 0,
    FOREIGN KEY (`menu_id`) REFERENCES `daqam_menu`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`ingredient_id`) REFERENCES `daqam_ingredients`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `uniq_resep` (`menu_id`,`ingredient_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `daqam_orders` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_no` VARCHAR(40) NOT NULL UNIQUE,
    `order_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `order_type` ENUM('dine_in','take_away') NOT NULL DEFAULT 'dine_in',
    `table_no` VARCHAR(10) NULL,
    `notes` VARCHAR(255) NULL,
    `payment_method` ENUM('tunai','qris') NOT NULL DEFAULT 'tunai',
    `total_amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `status` ENUM('selesai','batal') NOT NULL DEFAULT 'selesai',
    `user_id` INT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `daqam_order_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT NOT NULL,
    `menu_id` INT NOT NULL,
    `quantity` INT NOT NULL,
    `unit_price` DECIMAL(12,2) NOT NULL,
    `notes` VARCHAR(120) NULL,
    `subtotal` DECIMAL(12,2) NOT NULL,
    FOREIGN KEY (`order_id`) REFERENCES `daqam_orders`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`menu_id`) REFERENCES `daqam_menu`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `daqam_shift_closings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NULL,
    `shift_date` DATE NOT NULL,
    `cash_in_drawer` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `cash_system` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `qris_total` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `difference` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `notes` VARCHAR(255) NULL,
    `closed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- DATA AWAL
-- =========================================================
INSERT INTO `units` (`id`,`code`,`name`,`color`,`icon`,`description`) VALUES
(1,'qmas','Qmas Air Mineral','#0284C7','bi-droplet','Distribusi & stok air mineral, penjualan galon, gelas, dan botol.'),
(2,'smcorner','Suara Muhammadiyah Corner','#059669','bi-bag','Ritel kain, kemeja, batik, dan perlengkapan Hizbul Wathan.'),
(3,'daqam','Daqam Caffe','#92400E','bi-cup-hot','Kasir POS makanan & minuman, manajemen menu dan bahan baku.');

-- Semua akun demo memakai password: password123
INSERT INTO `users` (`id`,`unit_id`,`username`,`password_hash`,`full_name`,`role`,`must_change_password`) VALUES
(1,NULL,'superadmin','$2y$10$i4GMgUp/cA6YVPUQClet8ueB77Sb2VwzbVPaTPpU4AZMgjZTVlEIi','Pimpinan BUMK UMGO','super_admin',1),
(2,1,'admin_qmas','$2y$10$i4GMgUp/cA6YVPUQClet8ueB77Sb2VwzbVPaTPpU4AZMgjZTVlEIi','Admin Qmas','admin_unit',1),
(3,1,'kasir_qmas','$2y$10$i4GMgUp/cA6YVPUQClet8ueB77Sb2VwzbVPaTPpU4AZMgjZTVlEIi','Kasir Qmas','kasir',1),
(4,2,'admin_smcorner','$2y$10$i4GMgUp/cA6YVPUQClet8ueB77Sb2VwzbVPaTPpU4AZMgjZTVlEIi','Admin SM Corner','admin_unit',1),
(5,3,'kasir_daqam','$2y$10$i4GMgUp/cA6YVPUQClet8ueB77Sb2VwzbVPaTPpU4AZMgjZTVlEIi','Kasir Daqam','kasir',1);

-- QMAS
INSERT INTO `qmas_products` (`id`,`name`,`sku`,`kind`,`size`,`price`,`cost`,`stock`,`min_stock`,`unit`) VALUES
(1,'QMAS GL 19L','QMS-G19','galon','19L',23000,17000,120,20,'galon'),
(2,'QMAS 240 ML','QMS-G22','gelas','240ml',24000,19000,500,100,'pcs'),
(3,'QMAS 600 ML','QMS-B60','botol','600ml',60000,54000,400,80,'botol'),
(4,'QMAS S 1.5L','QMS-B15','botol','1.5L',70000,61000,250,50,'botol'),
(5,'Dus Gelas 220ml (isi 24)','QMS-DG22','dus','1 dus',45000,0,40,10,'dus'),
(6,'Dus Botol 600ml (isi 24)','QMS-DB60','dus','1 dus',90000,0,25,8,'dus'),
(7,'QMAS 330ML','QMS-330','botol','330ml',52000,44000,0,0,'botol');

INSERT INTO `qmas_transactions` (`id`,`transaction_no`,`transaction_date`,`customer_name`,`customer_type`,`payment_status`,`bank`,`total_amount`,`user_id`) VALUES
(1,CONCAT('QMS-',DATE_FORMAT(CURDATE(),'%Y%m%d'),'-0001'),NOW(),'Fakultas Agama Islam','fakultas','transfer','BSI',138000,2),
(2,CONCAT('QMS-',DATE_FORMAT(CURDATE(),'%Y%m%d'),'-0002'),NOW(),'Mahasiswa UMGO','mahasiswa','tunai',NULL,480000,3),
(3,CONCAT('QMS-',DATE_FORMAT(CURDATE(),'%Y%m%d'),'-0003'),NOW(),'Fakultas Ekonomi','fakultas','piutang',NULL,90000,2),
(4,CONCAT('QMS-',DATE_FORMAT(DATE_SUB(CURDATE(),INTERVAL 1 DAY),'%Y%m%d'),'-0001'),DATE_SUB(NOW(),INTERVAL 1 DAY),'Umum','umum','transfer','MUAMALAT',69000,3),
(5,CONCAT('QMS-',DATE_FORMAT(DATE_SUB(CURDATE(),INTERVAL 2 DAY),'%Y%m%d'),'-0001'),DATE_SUB(NOW(),INTERVAL 2 DAY),'Bakso Pak RT','umum','tunai',NULL,960000,2);

INSERT INTO `qmas_transaction_items` (`transaction_id`,`product_id`,`quantity`,`unit_price`,`unit_cost`,`subtotal`) VALUES
(1,1,6,23000,0,138000),
(2,2,10,24000,0,240000),(2,3,4,60000,0,240000),
(3,5,2,45000,0,90000),
(4,1,3,23000,0,69000),
(5,3,8,60000,0,480000),(5,2,20,24000,0,480000);

INSERT INTO `qmas_gallon_exchanges` (`exchange_date`,`customer_name`,`full_out`,`empty_in`,`notes`,`user_id`) VALUES
(NOW(),'Fakultas Agama Islam',6,5,'Tukar galon habis',2),
(NOW(),'Umum',2,2,'',3),
(DATE_SUB(NOW(),INTERVAL 1 DAY),'Fakultas Ekonomi',4,4,'Pengiriman rutin',2);

-- SM CORNER
INSERT INTO `sm_categories` (`id`,`name`,`description`) VALUES
(1,'Kain & Bahan Seragam','Kain muhammadiyah, batik, dan bahan jahit satuan meteran.'),
(2,'Baju Kemeja','Kemeja Muhammadiyah, Batik, dan Jas Almamater.'),
(3,'Peralatan Hizbul Wathan','Seragam HW, hasduk, topi, badge, tongkat, tali.');

INSERT INTO `sm_products` (`id`,`category_id`,`name`,`sku`,`size`,`price`,`cost`,`stock`,`min_stock`,`unit`) VALUES
(1,1,'Kain Muhammadiyah','SMC-KM01','meter',85000,0,40,10,'meter'),
(2,1,'Kain Batik Muhammadiyah','SMC-KB02','meter',95000,0,25,8,'meter'),
(3,2,'Kemeja Muhammadiyah','SMC-KM-M','M',150000,0,12,8,'pcs'),
(4,2,'Kemeja Muhammadiyah','SMC-KM-L','L',150000,0,6,8,'pcs'),
(5,2,'Batik Nasional','SMC-BN-XL','XL',175000,0,15,5,'pcs'),
(6,2,'Jas Almamater','SMC-JA-L','L',220000,0,6,5,'pcs'),
(7,3,'Seragam HW Lengkap','SMC-HW-M','M',135000,0,20,6,'set'),
(8,3,'Hasduk / Setangan Leher HW','SMC-HS-01','all',25000,0,60,15,'pcs');

INSERT INTO `sm_transactions` (`id`,`transaction_no`,`transaction_date`,`customer_name`,`payment_method`,`total_amount`,`user_id`) VALUES
(1,CONCAT('SMC-',DATE_FORMAT(CURDATE(),'%Y%m%d'),'-0001'),NOW(),'Mahasiswa','tunai',325000,4),
(2,CONCAT('SMC-',DATE_FORMAT(CURDATE(),'%Y%m%d'),'-0002'),NOW(),'Dosen','qris',170000,4),
(3,CONCAT('SMC-',DATE_FORMAT(DATE_SUB(CURDATE(),INTERVAL 1 DAY),'%Y%m%d'),'-0001'),DATE_SUB(NOW(),INTERVAL 1 DAY),'Umum','tunai',135000,4),
(4,CONCAT('SMC-',DATE_FORMAT(DATE_SUB(CURDATE(),INTERVAL 3 DAY),'%Y%m%d'),'-0001'),DATE_SUB(NOW(),INTERVAL 3 DAY),'HW UMGO','transfer',270000,4);

INSERT INTO `sm_transaction_items` (`transaction_id`,`product_id`,`quantity`,`unit_price`,`unit_cost`,`subtotal`) VALUES
(1,3,1,150000,0,150000),(1,7,1,135000,0,135000),(1,8,4,25000,0,100000),
(2,5,1,175000,0,175000),
(3,7,1,135000,0,135000),
(4,1,2,85000,0,170000),(4,8,4,25000,0,100000);

-- DAQAM
INSERT INTO `daqam_categories` (`id`,`name`) VALUES
(1,'Kopi'),(2,'Non-Kopi'),(3,'Makanan Berat'),(4,'Snack');

INSERT INTO `daqam_menu` (`id`,`category_id`,`name`,`sku`,`price`,`status`,`icon`) VALUES
(1,1,'Kopi Susu','DQ-KS01',18000,'tersedia','☕'),
(2,1,'Kopi Hitam','DQ-KH02',15000,'tersedia','☕'),
(3,1,'Americano','DQ-AM03',22000,'tersedia','☕'),
(4,2,'Teh Tawar','DQ-TT04',8000,'tersedia','🍵'),
(5,2,'Teh Manis','DQ-TM05',10000,'tersedia','🍵'),
(6,3,'Nasi Goreng','DQ-NG06',28000,'tersedia','🍛'),
(7,3,'Ayam Geprek','DQ-AG07',32000,'habis','🍗'),
(8,4,'Roti Bakar','DQ-RB08',16000,'tersedia','🍞');

INSERT INTO `daqam_ingredients` (`id`,`name`,`unit`,`stock`,`min_stock`,`last_price`) VALUES
(1,'Kopi Bubuk','gram',2000,500,120000),
(2,'Gula Pasir','gram',5000,1000,15000),
(3,'Susu Kental Manis','pcs',80,20,18000),
(4,'Daun Teh','gram',800,200,60000),
(5,'Beras','kg',30,10,12000),
(6,'Ayam','kg',12,5,38000),
(7,'Roti Tawar','pcs',40,10,12000);

INSERT INTO `daqam_recipes` (`menu_id`,`ingredient_id`,`quantity`) VALUES
(1,1,15),(1,2,10),(1,3,20),
(2,1,15),(2,2,10),
(3,1,20),
(4,4,5),(5,4,5),(5,2,10),
(6,5,0.3),(6,6,0.25),
(7,6,0.3),
(8,7,2);

INSERT INTO `daqam_orders` (`id`,`order_no`,`order_date`,`order_type`,`table_no`,`notes`,`payment_method`,`total_amount`,`status`,`user_id`) VALUES
(1,CONCAT('DQ-',DATE_FORMAT(CURDATE(),'%Y%m%d'),'-0001'),NOW(),'dine_in','T03','Less sugar','tunai',36000,'selesai',5),
(2,CONCAT('DQ-',DATE_FORMAT(CURDATE(),'%Y%m%d'),'-0002'),NOW(),'take_away',NULL,'','qris',46000,'selesai',5),
(3,CONCAT('DQ-',DATE_FORMAT(CURDATE(),'%Y%m%d'),'-0003'),NOW(),'dine_in','T01','Extra ice','tunai',28000,'selesai',5),
(4,CONCAT('DQ-',DATE_FORMAT(DATE_SUB(CURDATE(),INTERVAL 1 DAY),'%Y%m%d'),'-0001'),DATE_SUB(NOW(),INTERVAL 1 DAY),'dine_in','T05','','qris',64000,'selesai',5);

INSERT INTO `daqam_order_items` (`order_id`,`menu_id`,`quantity`,`unit_price`,`notes`,`subtotal`) VALUES
(1,1,2,18000,'Less sugar',36000),
(2,6,1,28000,'',28000),(2,8,1,16000,'',16000),
(3,6,1,28000,'',28000),
(4,1,2,18000,'',36000),(4,7,1,32000,'',32000);

INSERT INTO `daqam_shift_closings` (`user_id`,`shift_date`,`cash_in_drawer`,`cash_system`,`qris_total`,`difference`,`notes`,`closed_at`) VALUES
(5,DATE_SUB(CURDATE(),INTERVAL 1 DAY),152000,120000,32000,0,'Shift sore – selisih 0',DATE_SUB(NOW(),INTERVAL 1 DAY));

INSERT INTO `activity_logs` (`user_id`,`action`,`description`,`ip_address`) VALUES
(1,'login','Login berhasil: superadmin','127.0.0.1'),
(4,'login','Login berhasil: admin_smcorner','127.0.0.1');
