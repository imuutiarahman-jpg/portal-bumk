<?php
require_once __DIR__ . '/../../config/app.php';
require_login();
require_unit('smcorner');

$title = 'Laporan Penjualan – SM Corner';
$activeUnit = 'smcorner';

$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to = date('Y-m-d');

$rows = all('SELECT * FROM sm_transactions WHERE DATE(transaction_date) BETWEEN ? AND ? ORDER BY transaction_date DESC', [$from,$to]);
$total = 0; foreach ($rows as $r) $total += (float)$r['total_amount'];
$top = all('SELECT p.name, SUM(i.quantity) qty, SUM(i.subtotal) omzet FROM sm_transaction_items i JOIN sm_products p ON p.id=i.product_id JOIN sm_transactions t ON t.id=i.transaction_id WHERE DATE(t.transaction_date) BETWEEN ? AND ? GROUP BY p.id ORDER BY qty DESC LIMIT 5', [$from,$to]);
$trendMap = [];
foreach ($rows as $r) { $k = date('M Y', strtotime($r['transaction_date'])); $trendMap[$k] = ($trendMap[$k] ?? 0) + (float) $r['total_amount']; }
$trendLabels = array_keys($trendMap); $trendData = array_values($trendMap);

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="laporan-smcorner-'.$from.'-sd-'.$to.'.csv"');
    $out = fopen('php://output','w'); fwrite($out,"\xEF\xBB\xBF");
    fputcsv($out,['No Transaksi','Tanggal','Pelanggan','Metode','Total']);
    foreach ($rows as $r) fputcsv($out,[$r['transaction_no'],tgl($r['transaction_date'],true),$r['customer_name'],$r['payment_method'],$r['total_amount']]);
    fputcsv($out,['','','','TOTAL',$total]); fclose($out); exit;
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div><h1 class="section-title mb-1"><i class="bi bi-file-earmark-bar-graph" style="color:var(--smcorner)"></i> Laporan Penjualan SM Corner</h1><div class="text-muted small">Rekap transaksi ritel & produk terlaris</div></div>
</div>

<div class="card content-card mb-3"><div class="card-body row g-2 align-items-end">
    <div class="col-md-3"><label class="form-label small fw-semibold">Dari</label><input type="date" id="tgl_from" class="form-control form-control-sm" value="<?= e($from) ?>"></div>
    <div class="col-md-3"><label class="form-label small fw-semibold">Sampai</label><input type="date" id="tgl_to" class="form-control form-control-sm" value="<?= e($to) ?>"></div>
    <div class="col-md-auto"><button class="btn btn-sm btn-outline-secondary" data-range="7">7 Hari</button> <button class="btn btn-sm btn-outline-secondary" data-range="30">30 Hari</button> <button class="btn btn-sm btn-primary" id="btnFilter">Tampilkan</button></div>
</div></div>
<script>document.getElementById('btnFilter').onclick=function(){location.href='<?= base_url('/modules/smcorner/laporan.php') ?>?from='+document.getElementById('tgl_from').value+'&to='+document.getElementById('tgl_to').value;};</script>

<div class="row g-3 mb-3">
    <div class="col-md-4"><div class="card kpi-card p-3"><span class="kpi-label">TOTAL PENJUALAN</span><div class="kpi-value text-success"><?= rupiah($total) ?></div></div></div>
    <div class="col-md-4"><div class="card kpi-card p-3"><span class="kpi-label">JUMLAH TRANSAKSI</span><div class="kpi-value"><?= angka(count($rows)) ?></div></div></div>
    <div class="col-md-4"><div class="card kpi-card p-3"><span class="kpi-label">RATA-RATA / TRANSAKSI</span><div class="kpi-value"><?= rupiah(count($rows) ? $total/count($rows) : 0) ?></div></div></div>
</div>

<div class="card content-card mb-3"><div class="card-body">
    <div class="chart-title"><i class="bi bi-graph-up"></i> Tren Penjualan pada Rentang Ini</div>
    <div class="chart-box sm"><?= chart('line', $trendLabels, [['label' => 'Penjualan', 'data' => $trendData, 'color' => '#059669']]) ?></div>
</div></div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card content-card">
            <div class="card-header d-flex justify-content-between"><span>Detail Transaksi</span><a class="btn btn-sm btn-success" href="<?= base_url('/modules/smcorner/laporan.php?from='.$from.'&to='.$to.'&export=csv') ?>"><i class="bi bi-file-earmark-excel"></i> Export Excel/CSV</a></div>
            <div class="table-responsive"><table class="table table-sm mb-0">
                <thead class="table-light"><tr><th>No</th><th>Tanggal</th><th>Pelanggan</th><th>Bayar</th><th class="text-end">Total</th></tr></thead>
                <tbody><?php if (!$rows): ?><tr><td colspan="5" class="empty-state">Tidak ada transaksi.</td></tr><?php endif; ?>
                <?php foreach ($rows as $r): ?><tr><td class="small"><?= e($r['transaction_no']) ?></td><td class="small"><?= tgl($r['transaction_date'],true) ?></td><td class="small"><?= e($r['customer_name']) ?></td><td class="small"><?= e(ucfirst($r['payment_method'])) ?></td><td class="text-end fw-semibold"><?= rupiah($r['total_amount']) ?></td></tr><?php endforeach; ?></tbody>
                <tfoot><tr class="fw-bold"><td colspan="4" class="text-end">TOTAL</td><td class="text-end text-success"><?= rupiah($total) ?></td></tr></tfoot>
            </table></div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card content-card"><div class="card-header">Produk Terlaris</div><div class="list-group list-group-flush">
            <?php if (!$top): ?><div class="list-group-item small text-muted">Belum ada data.</div><?php endif; ?>
            <?php foreach ($top as $i=>$t): ?><div class="list-group-item d-flex justify-content-between small"><span><?= $i+1 ?>. <?= e($t['name']) ?></span><span class="badge bg-light text-dark"><?= angka($t['qty']) ?> terjual</span></div><?php endforeach; ?>
        </div></div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
