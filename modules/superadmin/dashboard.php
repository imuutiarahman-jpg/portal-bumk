<?php
require_once __DIR__ . '/../../config/app.php';
require_login();
require_role(['super_admin']);

$title = 'Ringkasan Omzet Gabungan';
$activeUnit = null;

$omzet = all("SELECT 'qmas' AS code, SUM(CASE WHEN DATE(transaction_date)=CURDATE() THEN total_amount ELSE 0 END) AS today,
                     SUM(CASE WHEN DATE_FORMAT(transaction_date,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m') THEN total_amount ELSE 0 END) AS month,
                     SUM(total_amount) AS total, COUNT(*) AS trx FROM qmas_transactions
              UNION ALL
              SELECT 'smcorner', SUM(CASE WHEN DATE(transaction_date)=CURDATE() THEN total_amount ELSE 0 END),
                     SUM(CASE WHEN DATE_FORMAT(transaction_date,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m') THEN total_amount ELSE 0 END),
                     SUM(total_amount), COUNT(*) FROM sm_transactions
              UNION ALL
              SELECT 'daqam', SUM(CASE WHEN DATE(order_date)=CURDATE() THEN total_amount ELSE 0 END),
                     SUM(CASE WHEN DATE_FORMAT(order_date,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m') THEN total_amount ELSE 0 END),
                     SUM(total_amount), COUNT(*) FROM daqam_orders WHERE status='selesai'");

$grandToday = 0; $grandMonth = 0; $grandTotal = 0; $grandTrx = 0;
foreach ($omzet as $o) {
    $grandToday += (float) $o['today'];
    $grandMonth += (float) $o['month'];
    $grandTotal += (float) $o['total'];
    $grandTrx += (int) $o['trx'];
}
$avgTrx = $grandTrx > 0 ? $grandTotal / $grandTrx : 0;

$recent = all("SELECT * FROM (
    SELECT 'Qmas Air Mineral' AS unit, transaction_no AS no, transaction_date AS waktu, customer_name AS nama, total_amount AS total,
           CASE WHEN payment_status = 'transfer' OR (payment_status='tunai' AND DATE(transaction_date)<'".LAPORAN_TUNAI_SEJAK."') THEN CONCAT('Transfer (', IFNULL(bank,'-'), ')') ELSE payment_status END AS keterangan,
           'qmas' AS code FROM qmas_transactions
    UNION ALL
    SELECT 'SM Corner', transaction_no, transaction_date, customer_name, total_amount, payment_method, 'smcorner' FROM sm_transactions
    UNION ALL
    SELECT 'Daqam Caffe', order_no, order_date, CONCAT(order_type,' ',IFNULL(table_no,'')), total_amount, payment_method, 'daqam' FROM daqam_orders
) x ORDER BY waktu DESC LIMIT 10");

$piutang = val('SELECT COUNT(*) FROM qmas_transactions WHERE payment_status="piutang"');

$trend = all("SELECT DATE_FORMAT(waktu,'%Y-%m') periode, SUM(total) omzet FROM (
    SELECT transaction_date waktu, total_amount total FROM qmas_transactions
    UNION ALL SELECT transaction_date, total_amount FROM sm_transactions
    UNION ALL SELECT order_date, total_amount FROM daqam_orders WHERE status='selesai'
) x WHERE waktu >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH) GROUP BY DATE_FORMAT(waktu,'%Y-%m') ORDER BY periode");
$trendLabels = []; $trendData = [];
foreach ($trend as $t) { $trendLabels[] = date('M Y', strtotime($t['periode'].'-01')); $trendData[] = (float) $t['omzet']; }

$comp = all("SELECT ".qmas_laporan_status_sql()." AS status, bank, SUM(total_amount) total FROM qmas_transactions GROUP BY status, bank ORDER BY total DESC");
$compLabels = []; $compData = [];
foreach ($comp as $c) { $compLabels[] = payment_label($c['status'], $c['bank']); $compData[] = (float) $c['total']; }

$unitLabels = []; $unitData = []; $unitColors = [];
foreach ($omzet as $o) { $u = unit_by_code($o['code']); $unitLabels[] = $u['name'] ?? $o['code']; $unitData[] = (float) $o['today']; $unitColors[] = '#0E9F90'; }

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-head">
    <div>
        <div class="section-eyebrow">Pimpinan BUMK UMGO</div>
        <h1 class="section-title"><i class="bi bi-bar-chart-line text-success"></i> Ringkasan Omzet Gabungan</h1>
        <div class="page-sub"><i class="bi bi-calendar3"></i> <?= date('d F Y') ?> · Data seluruh unit usaha</div>
    </div>
    <a href="<?= base_url('/modules/portal/index.php') ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Portal</a>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card kpi-card p-3"><div class="d-flex justify-content-between"><span class="kpi-label">OMZET HARI INI</span><span class="kpi-icon bg-success-subtle text-success"><i class="bi bi-cash-coin"></i></span></div><div class="kpi-value text-success"><?= rupiah($grandToday) ?></div><div class="kpi-foot">Bulan ini <?= rupiah($grandMonth) ?></div></div></div>
    <div class="col-md-3"><div class="card kpi-card p-3"><div class="d-flex justify-content-between"><span class="kpi-label">OMZET BULAN INI</span><span class="kpi-icon bg-primary-subtle text-primary"><i class="bi bi-calendar-check"></i></span></div><div class="kpi-value text-primary"><?= rupiah($grandMonth) ?></div><div class="kpi-foot">Total keseluruhan <?= rupiah($grandTotal) ?></div></div></div>
    <div class="col-md-3"><div class="card kpi-card p-3"><div class="d-flex justify-content-between"><span class="kpi-label">TOTAL TRANSAKSI</span><span class="kpi-icon bg-warning-subtle text-warning"><i class="bi bi-receipt"></i></span></div><div class="kpi-value"><?= angka($grandTrx) ?> <small class="fs-6 text-muted">trx</small></div><div class="kpi-foot">Rata-rata <?= rupiah($avgTrx) ?> / transaksi</div></div></div>
    <div class="col-md-3"><div class="card kpi-card p-3"><div class="d-flex justify-content-between"><span class="kpi-label">PIUTANG QMAS</span><span class="kpi-icon bg-danger-subtle text-danger"><i class="bi bi-exclamation-triangle"></i></span></div><div class="kpi-value text-danger"><?= angka($piutang) ?> <small class="fs-6 text-muted">nota</small></div><?php if ($piutang > 0): ?><div class="kpi-trend danger"><i class="bi bi-arrow-up-right"></i> Perlu ditindaklanjuti</div><?php else: ?><div class="kpi-trend"><i class="bi bi-check-circle"></i> Tidak ada piutang</div><?php endif; ?></div></div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-8">
        <div class="card content-card h-100"><div class="card-body">
            <div class="chart-title"><i class="bi bi-graph-up"></i> Tren Omzet Gabungan (12 Bulan Terakhir)</div>
            <div class="chart-box"><?= chart('line', $trendLabels, [['label' => 'Omzet', 'data' => $trendData, 'color' => '#0B4F3E']]) ?></div>
        </div></div>
    </div>
    <div class="col-lg-4">
        <div class="card content-card h-100"><div class="card-body">
            <div class="chart-title"><i class="bi bi-pie-chart"></i> Komposisi Pembayaran Qmas</div>
            <div class="chart-box"><?= chart('doughnut', $compLabels, [['label' => 'Total', 'data' => $compData]]) ?></div>
        </div></div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card content-card h-100">
            <div class="card-header">Kontribusi Omzet per Unit</div>
            <div class="card-body p-4">
                <div class="chart-box sm mb-3"><?= chart('bar', $unitLabels, [['label' => 'Omzet hari ini', 'data' => $unitData, 'color' => '#0B4F3E', 'colors' => $unitColors]]) ?></div>
                <?php foreach ($omzet as $o):
                    $u = unit_by_code($o['code']);
                    $pct = $grandToday > 0 ? ((float)$o['today'] / $grandToday) * 100 : 0; ?>
                    <div class="mb-4">
                        <div class="d-flex justify-content-between small mb-1">
                            <span class="fw-semibold"><?= e($u['name'] ?? $o['code']) ?></span>
                            <span><?= rupiah($o['today']) ?></span>
                        </div>
                        <div class="progress" style="height:10px">
                            <div class="progress-bar" role="progressbar" style="width:<?= min(100, $pct) ?>%"></div>
                        </div>
                        <div class="small text-muted mt-1">Bulan ini <?= rupiah($o['month']) ?> · <?= angka($o['trx']) ?> transaksi</div>
                    </div>
                <?php endforeach; ?>
                <hr>
                <div class="d-flex justify-content-between fw-bold"><span>Total Keseluruhan</span><span class="text-success"><?= rupiah($grandTotal) ?></span></div>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card content-card">
            <div class="card-header d-flex justify-content-between align-items-center">Transaksi Terbaru (Seluruh Unit)<span class="badge bg-light text-dark">10 terakhir</span></div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead class="table-light"><tr><th>Unit</th><th>No. Transaksi</th><th>Waktu</th><th>Pelanggan</th><th class="text-end">Total</th><th>Ket.</th></tr></thead>
                    <tbody>
                    <?php if (!$recent): ?><tr><td colspan="6" class="empty-state">Belum ada transaksi.</td></tr><?php endif; ?>
                    <?php foreach ($recent as $r): ?>
                        <tr>
                            <td><span class="badge text-white" style="background:var(--brand-teal)"><?= e(explode(' ', $r['unit'])[0]) ?></span></td>
                            <td class="small"><?= e($r['no']) ?></td>
                            <td class="small"><?= tgl($r['waktu'], true) ?></td>
                            <td class="small"><?= e($r['nama']) ?></td>
                            <td class="text-end fw-semibold"><?= rupiah($r['total']) ?></td>
                            <td class="small"><?= e($r['keterangan']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
