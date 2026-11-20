-- =========================================================
-- Tambah kolom is_active pada qmas_products (soft delete)
-- Aman dijalankan berulang.
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

CALL `bumk_add_col`('qmas_products', 'is_active', '`is_active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `unit`');
DROP PROCEDURE IF EXISTS `bumk_add_col`;

UPDATE `qmas_products` SET `is_active` = 1 WHERE `is_active` IS NULL;
SELECT `id`, `sku`, `name`, `is_active` FROM `qmas_products` ORDER BY `id`;
