-- =========================================================
-- Sesuaikan harga & HPP master Qmas dengan sheet asli
-- Aman dijalankan berulang.
-- =========================================================
USE `bumk_umgo`;

UPDATE `qmas_products` SET `price` = 23000, `cost` = 17000 WHERE `sku` = 'QMS-G19';
UPDATE `qmas_products` SET `price` = 24000, `cost` = 19000 WHERE `sku` = 'QMS-240';
UPDATE `qmas_products` SET `price` = 52000, `cost` = 44000 WHERE `sku` = 'QMS-330';
UPDATE `qmas_products` SET `price` = 60000, `cost` = 54000 WHERE `sku` = 'QMS-B60';
UPDATE `qmas_products` SET `price` = 70000, `cost` = 61000 WHERE `sku` = 'QMS-B15';

SELECT `sku`, `name`, `price`, `cost` FROM `qmas_products` ORDER BY `id`;
