<?php
require_once __DIR__ . '/../../config/app.php';
require_login();
require_unit('qmas');

$title = 'Dashboard Qmas Air Mineral';
$activeUnit = 'qmas';

$omzetToday = val('SELECT COALESCE(SUM(total_amount),0) FROM qmas_transactions WHERE DATE(transaction_date)=CURDATE()');
$galonToday = val('SELECT COALESCE(SUM(i.quantity),0) FROM qmas_transaction_items i JOIN qmas_products p ON p.id=i.product_id JOIN qmas_transactions t ON t.id=i.transaction_id WHERE p.kind="galon" AND DATE(t.transaction_date)=CURDATE()');
// DUS TERJUAL: item non-galon yang terjual hari ini (galon punya KPI tersendiri).
$dusToday   = val('SELECT COALESCE(SUM(i.quantity),0) FROM qmas_transaction_items i JOIN qmas_products p ON p.id=i.product_id JOIN qmas_transactions t ON t.id=i.transaction_id WHERE p.kind <> "galon" AND DATE(t.transaction_date)=CURDATE()');
$stokTotal  = val('SELECT COALESCE(SUM(stock),0) FROM qmas_products WHERE is_active=1');
$piutang    = val('SELECT COALESCE(SUM(total_amount),0) FROM qmas_transactions WHERE payment_status="piutang"');
$piutangLunas = val('SELECT COALESCE(SUM(total_amount),0) FROM qmas_transactions WHERE payment_status="piutang" AND lunas_date IS NOT NULL');
// Hutang ke supplier/perusahaan saat pengambilan stok (piutang pembelian).
$hutangStok      = val('SELECT COALESCE(SUM(total_amount),0) FROM qmas_purchases WHERE payment_status="piutang" AND lunas_date IS NULL');
$hutangStokNota  = (int) val('SELECT COUNT(*) FROM qmas_purchases WHERE payment_status="piutang" AND lunas_date IS NULL');
$hutangStokLunas = val('SELECT COALESCE(SUM(total_amount),0) FROM qmas_purchases WHERE lunas_date IS NOT NULL');

$recent = all('SELECT * FROM qmas_transactions ORDER BY transaction_date DESC LIMIT 8');
$lowStock = all('SELECT * FROM qmas_products WHERE is_active=1 AND stock <= min_stock ORDER BY stock ASC');
$piutangList = all('SELECT * FROM qmas_transactions WHERE payment_status="piutang" ORDER BY transaction_date DESC LIMIT 5');

$trend = all("SELECT DATE_FORMAT(transaction_date,'%Y-%m') periode, SUM(total_amount) omzet FROM qmas_transactions
              WHERE transaction_date >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH) GROUP BY periode ORDER BY periode");
$trendLabels = []; $trendData = [];
foreach ($trend as $t) { $trendLabels[] = date('M Y', strtotime($t['periode'].'-01')); $trendData[] = (float) $t['omzet']; }

$comp = all("SELECT ".qmas_laporan_status_sql()." AS status, bank, SUM(total_amount) total FROM qmas_transactions GROUP BY status, bank ORDER BY total DESC");
$compLabels = []; $compData = [];
foreach ($comp as $c) { $compLabels[] = payment_label($c['status'], $c['bank']); $compData[] = (float) $c['total']; }

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-head">
    <div>
        <div class="section-eyebrow">Unit Usaha · Qmas</div>
        <h1 class="section-title"><i class="bi bi-droplet-fill" style="color:var(--qmas)"></i> Dashboard Qmas Air Mineral</h1>
        <div class="page-sub">Distribusi, penjualan, dan stok air mineral · <?= date('d F Y') ?></div>
    </div>
    <a href="<?= base_url('/modules/qmas/transaksi.php') ?>" class="btn btn-sm text-white" style="background:var(--qmas)"><i class="bi bi-plus-lg"></i> Transaksi Baru</a>
</div>

<div class="row g-3 mb-4 row-cols-1 row-cols-sm-2 row-cols-lg-3 row-cols-xl-5">
    <div class="col"><div class="card kpi-card p-3"><div class="d-flex justify-content-between"><span class="kpi-label">OMZET HARI INI</span><span class="kpi-icon bg-primary-subtle text-primary"><i class="bi bi-cash-coin"></i></span></div><div class="kpi-value" style="color:var(--qmas)"><?= rupiah($omzetToday) ?></div><div class="kpi-foot">Piutang pelanggan <?= rupiah($piutang) ?></div></div></div>
    <div class="col"><div class="card kpi-card p-3"><div class="d-flex justify-content-between"><span class="kpi-label">GALON TERJUAL</span><span class="kpi-icon bg-info-subtle text-info"><i class="bi bi-droplet"></i></span></div><div class="kpi-value"><?= angka($galonToday) ?> <small class="fs-6 text-muted">galon</small></div><div class="kpi-foot">Penjualan galon hari ini</div></div></div>
    <div class="col"><div class="card kpi-card p-3"><div class="d-flex justify-content-between"><span class="kpi-label">DUS TERJUAL</span><span class="kpi-icon bg-warning-subtle text-warning"><i class="bi bi-box-seam"></i></span></div><div class="kpi-value"><?= angka($dusToday) ?> <small class="fs-6 text-muted">dus</small></div><div class="kpi-foot">Penjualan dus hari ini</div></div></div>
    <div class="col"><div class="card kpi-card p-3"><div class="d-flex justify-content-between"><span class="kpi-label">SISA STOK GUDANG</span><span class="kpi-icon bg-success-subtle text-success"><i class="bi bi-archive"></i></span></div><div class="kpi-value"><?= angka($stokTotal) ?> <small class="fs-6 text-muted">unit</small></div><?php if ($lowStock): ?><div class="kpi-trend danger"><i class="bi bi-exclamation-triangle"></i> <?= count($lowStock) ?> produk menipis</div><?php else: ?><div class="kpi-trend"><i class="bi bi-check-circle"></i> Semua stok aman</div><?php endif; ?></div></div>
    <div class="col"><div class="card kpi-card p-3"><div class="d-flex justify-content-between"><span class="kpi-label">PIUTANG PEMBELIAN</span><span class="kpi-icon bg-warning-subtle text-warning"><i class="bi bi-wallet2"></i></span></div><div class="kpi-value text-warning"><?= rupiah($hutangStok) ?></div><div class="kpi-foot"><?= angka($hutangStokNota) ?> nota belum dibayar</div><div class="kpi-foot">Sudah lunas: <?= rupiah($hutangStokLunas) ?></div></div></div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-8">
        <div class="card content-card h-100"><div class="card-body">
            <div class="chart-title"><i class="bi bi-graph-up"></i> Tren Omzet Qmas (12 Bulan Terakhir)</div>
            <div class="chart-box"><?= chart('line', $trendLabels, [['label' => 'Omzet', 'data' => $trendData, 'color' => '#0284C7']]) ?></div>
        </div></div>
    </div>
    <div class="col-lg-4">
        <div class="card content-card h-100"><div class="card-body">
            <div class="chart-title"><i class="bi bi-pie-chart"></i> Komposisi Pembayaran</div>
            <div class="chart-box"><?= chart('doughnut', $compLabels, [['label' => 'Total', 'data' => $compData]]) ?></div>
        </div></div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card content-card">
            <div class="card-header d-flex justify-content-between align-items-center">Transaksi & Distribusi Terbaru <a href="<?= base_url('/modules/qmas/transaksi.php') ?>" class="small">Lihat semua →</a></div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead class="table-light"><tr><th>No</th><th>Tanggal</th><th>Pelanggan</th><th>Jenis</th><th>Status</th><th class="text-end">Total</th></tr></thead>
                    <tbody>
                    <?php if (!$recent): ?><tr><td colspan="6" class="empty-state">Belum ada transaksi.</td></tr><?php endif; ?>
                    <?php foreach ($recent as $r): ?>
                        <tr>
                            <td class="small"><?= e($r['transaction_no']) ?></td>
                            <td class="small"><?= tgl($r['transaction_date'], true) ?></td>
                            <td class="small"><?= e($r['customer_name']) ?></td>
                            <td><span class="badge bg-light text-dark"><?= e(ucfirst($r['customer_type'])) ?></span></td>
                            <td><span class="badge bg-<?= payment_badge($r['payment_status']) ?>"><?= e(payment_label($r['payment_status'], $r['bank'])) ?></span></td>
                            <td class="text-end fw-semibold"><?= rupiah($r['total_amount']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card content-card mb-3">
            <div class="card-header text-danger"><i class="bi bi-exclamation-triangle"></i> Stok Menipis</div>
            <div class="list-group list-group-flush">
                <?php if (!$lowStock): ?><div class="list-group-item small text-muted">Semua stok aman.</div><?php endif; ?>
                <?php foreach ($lowStock as $p): ?>
                    <div class="list-group-item d-flex justify-content-between small"><span><?= e($p['name']) ?></span><span class="badge bg-danger"><?= angka($p['stock']) ?> <?= e($p['unit']) ?></span></div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="card content-card">
            <div class="card-header text-warning"><i class="bi bi-wallet2"></i> Piutang Belum Lunas (<?= rupiah($piutang) ?>)</div>
            <div class="list-group list-group-flush">
                <div class="list-group-item small text-success"><i class="bi bi-check-circle"></i> Sudah lunas: <b><?= rupiah($piutangLunas) ?></b></div>
                <?php if (!$piutangList): ?><div class="list-group-item small text-muted">Tidak ada piutang.</div><?php endif; ?>
                <?php foreach ($piutangList as $p): ?>
                    <div class="list-group-item d-flex justify-content-between small"><span><?= e($p['customer_name']) ?></span><span class="fw-semibold"><?= rupiah($p['total_amount']) ?></span></div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
