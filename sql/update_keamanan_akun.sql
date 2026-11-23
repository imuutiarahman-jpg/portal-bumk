-- =========================================================
-- Pengamanan akun: forced password change, lockout, audit
-- Aman dijalankan berulang.
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

CALL `bumk_add_col`('users','must_change_password','`must_change_password` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_active`');
CALL `bumk_add_col`('users','failed_attempts','`failed_attempts` INT NOT NULL DEFAULT 0 AFTER `must_change_password`');
CALL `bumk_add_col`('users','locked_until','`locked_until` DATETIME NULL AFTER `failed_attempts`');
CALL `bumk_add_col`('users','last_password_changed','`last_password_changed` DATETIME NULL AFTER `locked_until`');
DROP PROCEDURE IF EXISTS `bumk_add_col`;

-- Akun bawaan wajib mengganti password pada login pertama
UPDATE `users` SET `must_change_password` = 1 WHERE `username` IN ('superadmin','admin_qmas','kasir_qmas','admin_smcorner','kasir_daqam');

SELECT `id`,`username`,`role`,`is_active`,`must_change_password`,`failed_attempts`,`locked_until` FROM `users` ORDER BY `id`;
