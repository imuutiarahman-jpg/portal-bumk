-- ============================================================
-- QMAS: pelunasan piutang pembelian (hutang ke supplier/perusahaan)
-- Jalankan sekali di database yang sudah punya tabel qmas_purchases.
-- ============================================================

USE `bumk_umgo`;

ALTER TABLE `qmas_purchases`
    ADD COLUMN `lunas_date` DATETIME NULL AFTER `bank`;

-- Verifikasi kolom
SHOW COLUMNS FROM `qmas_purchases` LIKE 'lunas_date';
