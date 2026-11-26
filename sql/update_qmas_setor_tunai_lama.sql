-- ============================================================
-- QMAS: opsional — samakan database dengan klasifikasi laporan
-- untuk transaksi tunai sebelum 24 November 2026.
--
-- Jalankan HANYA JIKA Anda ingin status di database ikut berubah
-- menjadi Transfer (misalnya uang tunai lama memang sudah disetor).
-- Kolom bank sengaja dikosongkan karena bank tujuan tidak tercatat.
-- ============================================================

USE `bumk_umgo`;

UPDATE `qmas_transactions`
SET `payment_status` = 'transfer',
    `setor_date` = COALESCE(`setor_date`, `transaction_date`)
WHERE `payment_status` = 'tunai'
  AND DATE(`transaction_date`) < '2026-11-24';

SELECT COUNT(*) AS `tunai_lama_tersisa`
FROM `qmas_transactions`
WHERE `payment_status` = 'tunai'
  AND DATE(`transaction_date`) < '2026-11-24';
