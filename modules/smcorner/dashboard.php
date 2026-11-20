<?php
require_once __DIR__ . '/../../config/app.php';
require_login();
require_unit('smcorner');

$title = 'Dashboard SM Corner';
$activeUnit = 'smcorner';

$salesToday = val('SELECT COALESCE(SUM(total_amount),0) FROM sm_transactions WHERE DATE(transaction_date)=CURDATE()');
$salesMonth = val('SELECT COALESCE(SUM(total_amount),0) FROM sm_transactions WHERE DATE_FORMAT(transaction_date,"%Y-%m")=DATE_FORMAT(CURDATE(),"%Y-%m")');
$bestSeller = row('SELECT p.name, SUM(i.quantity) qty FROM sm_transaction_items i JOIN sm_products p ON p.id=i.product_id GROUP BY p.id ORDER BY qty DESC LIMIT 1');
$lowCount   = val('SELECT COUNT(*) FROM sm_products WHERE stock <= min_stock');
$inventory  = val('SELECT COALESCE(SUM(stock*price),0) FROM sm_products');
$purchaseMonth = val('SELECT COALESCE(SUM(total_amount),0) FROM sm_purchases WHERE DATE_FORMAT(purchase_date,"%Y-%m")=DATE_FORMAT(CURDATE(),"%Y-%m")');
$sheetValue = val('SELECT inventory_value FROM sm_catalog_summary WHERE is_total=1 ORDER BY id DESC LIMIT 1');

$recent = all('SELECT * FROM sm_transactions ORDER BY transaction_date DESC LIMIT 8');
$lowStock = all('SELECT * FROM sm_products WHERE stock <= min_stock ORDER BY stock ASC LIMIT 8');
$categories = all('SELECT c.name, COUNT(p.id) total FROM sm_categories c LEFT JOIN sm_products p ON p.category_id=c.id GROUP BY c.id');

$trend = all("SELECT DATE_FORMAT(transaction_date,'%Y-%m') periode, SUM(total_amount) omzet FROM sm_transactions
              WHERE transaction_date >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH) GROUP BY periode ORDER BY periode");
$trendLabels = []; $trendData = [];
foreach ($trend as $t) { $trendLabels[] = date('M Y', strtotime($t['periode'].'-01')); $trendData[] = (float) $t['omzet']; }
$pay = all('SELECT payment_method metode, SUM(total_amount) total FROM sm_transactions GROUP BY payment_method');
$payLabels = []; $payData = [];
foreach ($pay as $p) { $payLabels[] = ucfirst($p['metode']); $payData[] = (float) $p['total']; }

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div><h1 class="section-title mb-1"><i class="bi bi-bag-check-fill" style="color:var(--smcorner)"></i> Dashboard Suara Muhammadiyah Corner</h1><div class="text-muted small">Ritel atribut persyarikatan & perlengkapan HW · <?= date('d F Y') ?></div></div>
    <a href="<?= base_url('/modules/smcorner/kasir.php') ?>" class="btn btn-sm text-white fw-bold" style="background:var(--smcorner)"><i class="bi bi-cart-plus"></i> Buka Kasir</a>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card kpi-card p-3"><div class="d-flex justify-content-between"><span class="kpi-label">PENJUALAN HARI INI</span><span class="kpi-icon bg-success-subtle text-success"><i class="bi bi-cash-coin"></i></span></div><div class="kpi-value" style="color:var(--smcorner)"><?= rupiah($salesToday) ?></div></div></div>
    <div class="col-md-3"><div class="card kpi-card p-3"><div class="d-flex justify-content-between"><span class="kpi-label">PRODUK TERLARIS</span><span class="kpi-icon bg-warning-subtle text-warning"><i class="bi bi-star-fill"></i></span></div><div class="kpi-value fs-6 mt-3"><?= e($bestSeller['name'] ?? '-') ?></div><div class="kpi-label"><?= angka($bestSeller['qty'] ?? 0) ?> terjual</div></div></div>
    <div class="col-md-3"><div class="card kpi-card p-3"><div class="d-flex justify-content-between"><span class="kpi-label">STOK MENIPIS</span><span class="kpi-icon bg-danger-subtle text-danger"><i class="bi bi-exclamation-triangle"></i></span></div><div class="kpi-value text-danger"><?= angka($lowCount) ?> <small class="fs-6 text-muted">produk</small></div></div></div>
    <div class="col-md-3"><div class="card kpi-card p-3"><div class="d-flex justify-content-between"><span class="kpi-label">NILAI INVENTARIS</span><span class="kpi-icon bg-primary-subtle text-primary"><i class="bi bi-boxes"></i></span></div><div class="kpi-value text-primary"><?= rupiah($inventory) ?></div><div class="kpi-label">Bulan ini <?= rupiah($salesMonth) ?> · Beli <?= rupiah($purchaseMonth) ?><?= $sheetValue ? ' · Sheet: '.rupiah($sheetValue) : '' ?></div></div></div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-8">
        <div class="card content-card h-100"><div class="card-body">
            <div class="chart-title"><i class="bi bi-graph-up"></i> Tren Penjualan (12 Bulan Terakhir)</div>
            <div class="chart-box"><?= chart('line', $trendLabels, [['label' => 'Penjualan', 'data' => $trendData, 'color' => '#059669']]) ?></div>
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
            <div class="card-header d-flex justify-content-between">Penjualan Terbaru <a href="<?= base_url('/modules/smcorner/laporan.php') ?>" class="small">Lihat laporan →</a></div>
            <div class="table-responsive"><table class="table table-sm table-hover mb-0">
                <thead class="table-light"><tr><th>No</th><th>Tanggal</th><th>Pelanggan</th><th>Bayar</th><th class="text-end">Total</th></tr></thead>
                <tbody><?php if (!$recent): ?><tr><td colspan="5" class="empty-state">Belum ada transaksi.</td></tr><?php endif; ?>
                <?php foreach ($recent as $r): ?><tr>
                    <td class="small"><?= e($r['transaction_no']) ?></td><td class="small"><?= tgl($r['transaction_date'], true) ?></td>
                    <td class="small"><?= e($r['customer_name']) ?></td><td><span class="badge bg-light text-dark"><?= e(ucfirst($r['payment_method'])) ?></span></td>
                    <td class="text-end fw-semibold"><?= rupiah($r['total_amount']) ?></td>
                </tr><?php endforeach; ?></tbody>
            </table></div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card content-card mb-3">
            <div class="card-header text-danger"><i class="bi bi-exclamation-triangle"></i> Low Stock Alert</div>
            <div class="list-group list-group-flush">
                <?php if (!$lowStock): ?><div class="list-group-item small text-muted">Semua stok aman.</div><?php endif; ?>
                <?php foreach ($lowStock as $p): ?><div class="list-group-item d-flex justify-content-between small"><span><?= e($p['name']).' ('.e($p['size']).')' ?></span><span class="badge bg-danger"><?= angka($p['stock'])?></span></div><?php endforeach; ?>
            </div>
        </div>
        <div class="card content-card">
            <div class="card-header">Katalog per Kategori</div>
            <div class="list-group list-group-flush">
                <?php foreach ($categories as $c): ?><div class="list-group-item d-flex justify-content-between small"><span><?= e($c['name']) ?></span><span class="badge bg-light text-dark"><?= $c['total'] ?> produk</span></div><?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
