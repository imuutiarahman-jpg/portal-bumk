-- ============================================================
-- QMAS: hitung ulang stok dari nol
-- Jalankan sekali setelah sql/update_qmas_opening_stock.sql.
-- Stok = 0 + pembelian - transaksi manual
-- Transaksi hasil import (import_batch IS NOT NULL) TIDAK mengurangi stok.
-- ============================================================

UPDATE `qmas_products` SET `opening_stock` = 0;

UPDATE `qmas_products` p
SET `stock` = GREATEST(0,
    COALESCE((
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
