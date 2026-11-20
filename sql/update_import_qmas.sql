-- =========================================================
-- MIGRASI: Import CSV Qmas + Rekap Bulanan
-- Tambah HPP, tanggal lunas/setor, penanda batch import.
-- Aman dijalankan berulang (kolom hanya ditambah jika belum ada).
-- =========================================================
USE `bumk_umgo`;

DROP PROCEDURE IF EXISTS `bumk_add_col`;
DELIMITER $$
CREATE PROCEDURE `bumk_add_col`(IN p_table VARCHAR(64), IN p_column VARCHAR(64), IN p_ddl TEXT)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = 'bumk_umgo' AND TABLE_NAME = p_table AND COLUMN_NAME = p_column
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN ', p_ddl);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END $$
DELIMITER ;

CALL `bumk_add_col`('qmas_products',        'cost',         '`cost` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `price`');
CALL `bumk_add_col`('qmas_transactions',    'lunas_date',   '`lunas_date` DATETIME NULL AFTER `bank`');
CALL `bumk_add_col`('qmas_transactions',    'setor_date',   '`setor_date` DATETIME NULL AFTER `lunas_date`');
CALL `bumk_add_col`('qmas_transactions',    'import_batch', '`import_batch` VARCHAR(80) NULL AFTER `user_id`');
CALL `bumk_add_col`('qmas_transaction_items','unit_cost',   '`unit_cost` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `unit_price`');

DROP PROCEDURE IF EXISTS `bumk_add_col`;

-- Data prices/produk mengikuti sheet "list order QMAS"
UPDATE `qmas_products` SET `name` = 'QMAS GL 19L',   `price` = 23000 WHERE `sku` = 'QMS-G19';
UPDATE `qmas_products` SET `name` = 'QMAS 600 ML',   `price` = 85000 WHERE `sku` = 'QMS-B60';
UPDATE `qmas_products` SET `name` = 'QMAS S 1.5L',   `price` = 70000, `cost` = 61000 WHERE `sku` = 'QMS-B15';
INSERT INTO `qmas_products` (`name`,`sku`,`kind`,`size`,`price`,`cost`,`stock`,`min_stock`,`unit`) VALUES
('QMAS GL 19L',  'QMS-G19','galon','19L',   23000, 0,    120, 20, 'galon'),
('QMAS 240 ML',  'QMS-240','gelas','240ml', 24000, 0,      0,  0, 'pcs'),
('QMAS 330ML',   'QMS-330','botol','330ml', 55000, 0,      0,  0, 'botol'),
('QMAS 600 ML',  'QMS-B60','botol','600ml', 85000, 0,    400, 80, 'botol'),
('QMAS S 1.5L',  'QMS-B15','botol','1.5L',  70000, 61000, 250, 50, 'botol')
ON DUPLICATE KEY UPDATE
    `name`  = VALUES(`name`),
    `price` = VALUES(`price`),
    `cost`  = VALUES(`cost`);

-- HPP historis untuk item yang sudah ada
UPDATE `qmas_transaction_items` i
JOIN `qmas_products` p ON p.id = i.product_id
SET i.`unit_cost` = p.`cost`
WHERE i.`unit_cost` = 0 AND p.`cost` > 0;
