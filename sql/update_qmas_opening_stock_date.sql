-- ============================================================
-- QMAS: stok awal bertanggal + penyeimbang selisih kartu stok
-- Jalankan sekali di database yang sudah memakai opening_stock.
-- (Jika database diimport dari sql/bumk_umgo.sql versi terbaru,
--  kedua kolom sudah ada — lewati perintah ALTER TABLE di bawah.)
--
-- Stok = opening_stock
--      + pembelian  dengan tanggal > opening_stock_date
--      - penjualan dengan tanggal > opening_stock_date
-- Semua pergerakan dibatasi sampai hari ini (<= NOW()).
-- ============================================================

USE `bumk_umgo`;

ALTER TABLE `qmas_products`
    ADD COLUMN `opening_stock_date` DATETIME NULL AFTER `opening_stock`;

-- Angka di bawah adalah penyeimbang selisih antara transaksi yang sudah
-- diinput di aplikasi dengan kolom Stok Keluar di kartu stok Qmas 2026.
-- Bukan stok fisik. Tanggal stok awal = 1 Januari 2026.
UPDATE `qmas_products` SET `opening_stock` = 17, `opening_stock_date` = '2026-01-01 00:00:00' WHERE `sku` = 'QMS-G19';
UPDATE `qmas_products` SET `opening_stock` = 18, `opening_stock_date` = '2026-01-01 00:00:00' WHERE `sku` IN ('QMS-G22','QMS-240');
UPDATE `qmas_products` SET `opening_stock` = 58, `opening_stock_date` = '2026-01-01 00:00:00' WHERE `sku` = 'QMS-330';
UPDATE `qmas_products` SET `opening_stock` = 129, `opening_stock_date` = '2026-01-01 00:00:00' WHERE `sku` = 'QMS-B60';
UPDATE `qmas_products` SET `opening_stock` = 11, `opening_stock_date` = '2026-01-01 00:00:00' WHERE `sku` = 'QMS-B15';

-- Hitung ulang stok seluruh produk.
UPDATE `qmas_products` p
SET `stock` = GREATEST(0,
    p.`opening_stock`
    + COALESCE((
        SELECT SUM(i.`quantity`) FROM `qmas_purchase_items` i
        JOIN `qmas_purchases` pu ON pu.`id` = i.`purchase_id`
        WHERE i.`product_id` = p.`id`
          AND pu.`purchase_date` <= NOW()
          AND (p.`opening_stock_date` IS NULL OR pu.`purchase_date` > p.`opening_stock_date`)
    ), 0)
    - COALESCE((
        SELECT SUM(i.`quantity`) FROM `qmas_transaction_items` i
        JOIN `qmas_transactions` t ON t.`id` = i.`transaction_id`
        WHERE i.`product_id` = p.`id`
          AND t.`transaction_date` <= NOW()
          AND (p.`opening_stock_date` IS NULL OR t.`transaction_date` > p.`opening_stock_date`)
    ), 0)
);

-- Verifikasi stok per 3 November 2026 (target: 3 / 56 / 25 / 29 / 5).
SELECT p.`name`,
    p.`opening_stock`
    + COALESCE((
        SELECT SUM(i.`quantity`) FROM `qmas_purchase_items` i
        JOIN `qmas_purchases` pu ON pu.`id` = i.`purchase_id`
        WHERE i.`product_id` = p.`id`
          AND pu.`purchase_date` > p.`opening_stock_date`
          AND pu.`purchase_date` <= '2026-11-03 23:59:59'
    ), 0)
    - COALESCE((
        SELECT SUM(i.`quantity`) FROM `qmas_transaction_items` i
        JOIN `qmas_transactions` t ON t.`id` = i.`transaction_id`
        WHERE i.`product_id` = p.`id`
          AND t.`transaction_date` > p.`opening_stock_date`
          AND t.`transaction_date` <= '2026-11-03 23:59:59'
    ), 0) AS `stok_3_nov`
FROM `qmas_products` p
ORDER BY p.`id`;
