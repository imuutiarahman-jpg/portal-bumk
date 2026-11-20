-- =========================================================
-- MIGRASI: Status Bayar Qmas
-- Dari: ENUM('lunas','piutang')
-- Ke  : ENUM('tunai','transfer','piutang') + kolom bank
-- Jalankan sekali saja pada database bumk_umgo yang sudah ada.
-- Data lama 'lunas' otomatis diubah menjadi 'tunai'.
-- =========================================================
USE `bumk_umgo`;

-- 1. Tambahkan nilai baru ke ENUM (nilai lama masih diterima)
ALTER TABLE `qmas_transactions`
    MODIFY `payment_status` ENUM('lunas','tunai','transfer','piutang') NOT NULL DEFAULT 'lunas';

-- 2. Konversi data lama
UPDATE `qmas_transactions` SET `payment_status` = 'tunai' WHERE `payment_status` = 'lunas';

-- 3. Hapus nilai lama dari ENUM
ALTER TABLE `qmas_transactions`
    MODIFY `payment_status` ENUM('tunai','transfer','piutang') NOT NULL DEFAULT 'tunai';

-- 4. Tambah kolom bank (hanya dipakai saat transfer)
ALTER TABLE `qmas_transactions`
    ADD COLUMN `bank` ENUM('BSI','MUAMALAT') NULL DEFAULT NULL AFTER `payment_status`;

-- 5. Opsional (data demo bawaan): jadikan 2 transaksi pertama sebagai contoh transfer
UPDATE `qmas_transactions` SET `payment_status` = 'transfer', `bank` = 'BSI' WHERE `id` = 1 AND `payment_status` = 'tunai';
UPDATE `qmas_transactions` SET `payment_status` = 'transfer', `bank` = 'MUAMALAT' WHERE `id` = 4 AND `payment_status` = 'tunai';
