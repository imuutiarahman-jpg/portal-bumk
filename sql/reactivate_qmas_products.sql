-- =========================================================
-- Kembalikan semua produk Qmas yang dinonaktifkan menjadi aktif
-- =========================================================
USE `bumk_umgo`;
UPDATE `qmas_products` SET `is_active` = 1 WHERE `is_active` = 0;
SELECT `id`, `sku`, `name`, `is_active` FROM `qmas_products` ORDER BY `id`;
