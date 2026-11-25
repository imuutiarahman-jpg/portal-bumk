-- ============================================================
-- QMAS: stok dihitung ulang berdasarkan tanggal
-- Jalankan sekali di database yang sudah ada.
-- Stok = opening_stock + pembelian - transaksi manual
-- Transaksi hasil import (import_batch IS NOT NULL) TIDAK mengurangi stok.
-- ============================================================

ALTER TABLE `qmas_products`
    ADD COLUMN `opening_stock` INT NOT NULL DEFAULT 0 AFTER `stock`;

-- Isi stok awal supaya angka stok saat ini tidak berubah setelah migrasi.
UPDATE `qmas_products` p
SET `opening_stock` = GREATEST(0,
    p.`stock`
    - COALESCE((
        SELECT SUM(i.`quantity`)
        FROM `qmas_purchase_items` i
        JOIN `qmas_purchases` pu ON pu.`id` = i.`purchase_id`
        WHERE i.`product_id` = p.`id`
    ), 0)
    + COALESCE((
        SELECT SUM(i.`quantity`)
        FROM `qmas_transaction_items` i
        JOIN `qmas_transactions` t ON t.`id` = i.`transaction_id`
        WHERE i.`product_id` = p.`id` AND t.`import_batch` IS NULL
    ), 0)
);

-- Hitung ulang stok dari stok awal + seluruh pembelian - transaksi manual.
UPDATE `qmas_products` p
SET `stock` = GREATEST(0,
    p.`opening_stock`
    + COALESCE((
        SELECT SUM(i.`quantity`)
        FROM `qmas_purchase_items` i
        JOIN `qmas_purchases` pu ON pu.`id` = i.`purchase_id`
        WHERE i.`product_id` = p.`id`
    ), 0)
    - COALESCE((
        SELECT SUM(i.`quantity`)
        FROM `qmas_transaction_items` i
        JOIN `qmas_transactions` t ON t.`id` = i.`transaction_id`
        WHERE i.`product_id` = p.`id` AND t.`import_batch` IS NULL
    ), 0)
);
