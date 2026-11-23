<?php
require_once __DIR__ . '/../../config/app.php';
require_login();
require_unit('daqam');

$title = 'Dashboard Daqam Caffe';
$activeUnit = 'daqam';

$revenue = val("SELECT COALESCE(SUM(total_amount),0) FROM daqam_orders WHERE DATE(order_date)=CURDATE() AND status='selesai'");
$orders  = val("SELECT COUNT(*) FROM daqam_orders WHERE DATE(order_date)=CURDATE() AND status='selesai'");
$avg     = $orders ? $revenue / $orders : 0;
$best    = row('SELECT m.name, SUM(i.quantity) qty FROM daqam_order_items i JOIN daqam_menu m ON m.id=i.menu_id GROUP BY m.id ORDER BY qty DESC LIMIT 1');
$lowBahan = all('SELECT * FROM daqam_ingredients WHERE stock <= min_stock ORDER BY stock ASC');
$recent = all('SELECT * FROM daqam_orders ORDER BY order_date DESC LIMIT 8');

$trend = all("SELECT DATE_FORMAT(order_date,'%Y-%m') periode, SUM(total_amount) omzet FROM daqam_orders
              WHERE order_date >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH) AND status='selesai' GROUP BY periode ORDER BY periode");
$trendLabels = []; $trendData = [];
foreach ($trend as $t) { $trendLabels[] = date('M Y', strtotime($t['periode'].'-01')); $trendData[] = (float) $t['omzet']; }
$pay = all("SELECT payment_method metode, SUM(total_amount) total FROM daqam_orders WHERE status='selesai' GROUP BY payment_method");
$payLabels = []; $payData = [];
foreach ($pay as $p) { $payLabels[] = strtoupper($p['metode']); $payData[] = (float) $p['total']; }

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-head">
    <div>
        <div class="section-eyebrow">Unit Usaha · Daqam</div>
        <h1 class="section-title"><i class="bi bi-cup-hot-fill" style="color:var(--daqam)"></i> Dashboard Daqam Caffe</h1>
        <div class="page-sub">Operasional kafe kampus · <?= date('d F Y') ?></div>
    </div>
    <a href="<?= base_url('/modules/daqam/kasir.php') ?>" class="btn btn-sm text-white fw-bold" style="background:var(--daqam)"><i class="bi bi-cart-plus"></i> Buka Kasir</a>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card kpi-card p-3"><div class="d-flex justify-content-between"><span class="kpi-label">PENDAPATAN HARI INI</span><span class="kpi-icon bg-warning-subtle text-warning"><i class="bi bi-cash-coin"></i></span></div><div class="kpi-value" style="color:var(--daqam)"><?= rupiah($revenue) ?></div><div class="kpi-foot">Order selesai hari ini</div></div></div>
    <div class="col-md-3"><div class="card kpi-card p-3"><div class="d-flex justify-content-between"><span class="kpi-label">JUMLAH ORDER</span><span class="kpi-icon bg-primary-subtle text-primary"><i class="bi bi-receipt"></i></span></div><div class="kpi-value"><?= angka($orders) ?> <small class="fs-6 text-muted">order</small></div><div class="kpi-foot">Rata-rata <?= rupiah($avg) ?> / order</div></div></div>
    <div class="col-md-3"><div class="card kpi-card p-3"><div class="d-flex justify-content-between"><span class="kpi-label">MENU PALING LARIS</span><span class="kpi-icon bg-success-subtle text-success"><i class="bi bi-star-fill"></i></span></div><div class="kpi-value fs-6 mt-3"><?= e($best['name'] ?? '-') ?></div><div class="kpi-foot"><?= angka($best['qty'] ?? 0) ?> porsi terjual</div></div></div>
    <div class="col-md-3"><div class="card kpi-card p-3"><div class="d-flex justify-content-between"><span class="kpi-label">RATA-RATA TRANSAKSI</span><span class="kpi-icon bg-info-subtle text-info"><i class="bi bi-graph-up"></i></span></div><div class="kpi-value"><?= rupiah($avg) ?></div><?php if ($lowBahan): ?><div class="kpi-trend danger"><i class="bi bi-exclamation-triangle"></i> <?= count($lowBahan) ?> bahan menipis</div><?php else: ?><div class="kpi-trend"><i class="bi bi-check-circle"></i> Bahan baku aman</div><?php endif; ?></div></div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-8">
        <div class="card content-card h-100"><div class="card-body">
            <div class="chart-title"><i class="bi bi-graph-up"></i> Tren Pendapatan (12 Bulan Terakhir)</div>
            <div class="chart-box"><?= chart('line', $trendLabels, [['label' => 'Pendapatan', 'data' => $trendData, 'color' => '#92400E']]) ?></div>
        </div></div>
    </div>
    <div class="col-lg-4">
        <div class="card content-card h-100"><div class="card-body">
            <div class="chart-title"><i class="bi bi-pie-chart"></i> Metode Pembayaran</div>
            <div class="chart-box"><?= chart('doughnut', $payLabels, [['label' => 'Total', 'data' => $payData]]) ?></div>
        </div></div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card content-card">
            <div class="card-header d-flex justify-content-between">Pesanan Terbaru <a href="<?= base_url('/modules/daqam/laporan.php') ?>" class="small">Lihat laporan →</a></div>
            <div class="table-responsive"><table class="table table-sm table-hover mb-0">
                <thead class="table-light"><tr><th>No. Order</th><th>Waktu</th><th>Tipe</th><th>Meja</th><th>Bayar</th><th class="text-end">Total</th></tr></thead>
                <tbody><?php if (!$recent): ?><tr><td colspan="6" class="empty-state">Belum ada pesanan.</td></tr><?php endif; ?>
                <?php foreach ($recent as $r): ?><tr>
                    <td class="small"><?= e($r['order_no']) ?></td><td class="small"><?= tgl($r['order_date'],true) ?></td>
                    <td class="small"><?= $r['order_type']==='dine_in'?'Dine In':'Take Away' ?></td><td class="small"><?= e($r['table_no'] ?: '-') ?></td>
                    <td><span class="badge bg-<?= $r['payment_method']==='qris'?'primary':'light text-dark' ?>"><?= e(strtoupper($r['payment_method'])) ?></span></td>
                    <td class="text-end fw-semibold"><?= rupiah($r['total_amount']) ?></td>
                </tr><?php endforeach; ?></tbody>
            </table></div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card content-card mb-3"><div class="card-header text-danger"><i class="bi bi-exclamation-triangle"></i> Bahan Baku Menipis</div><div class="list-group list-group-flush">
            <?php if (!$lowBahan): ?><div class="list-group-item small text-muted">Semua bahan aman.</div><?php endif; ?>
            <?php foreach ($lowBahan as $b): ?><div class="list-group-item d-flex justify-content-between small"><span><?= e($b['name']) ?></span><span class="badge bg-danger"><?= angka($b['stock']).' '.e($b['unit']) ?></span></div><?php endforeach; ?>
        </div></div>
        <div class="card content-card"><div class="card-header">Penjualan per Metode Bayar</div><div class="card-body">
            <?php $methods = all("SELECT payment_method, SUM(total_amount) total, COUNT(*) n FROM daqam_orders WHERE DATE(order_date)=CURDATE() GROUP BY payment_method");
            foreach ($methods as $m): $pct = $revenue>0 ? (float)$m['total']/$revenue*100 : 0; ?>
                <div class="mb-3"><div class="d-flex justify-content-between small"><span><?= e(strtoupper($m['payment_method'])) ?></span><span><?= rupiah($m['total']) ?></span></div><div class="progress" style="height:8px"><div class="progress-bar" style="width:<?= $pct ?>%;background:var(--daqam)"></div></div></div>
            <?php endforeach; if (!$methods) echo '<span class="small text-muted">Belum ada data hari ini.</span>'; ?>
        </div></div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
