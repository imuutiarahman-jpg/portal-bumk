<?php
require_once __DIR__ . '/../../config/app.php';
require_login();
require_unit('daqam');

$title = 'Laporan Penjualan – Daqam';
$activeUnit = 'daqam';

$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to = date('Y-m-d');

$rows = all('SELECT * FROM daqam_orders WHERE DATE(order_date) BETWEEN ? AND ? ORDER BY order_date DESC',[$from,$to]);
$total=0; foreach($rows as $r) $total += (float)$r['total_amount'];
$top = all('SELECT m.name, SUM(i.quantity) qty, SUM(i.subtotal) omzet FROM daqam_order_items i JOIN daqam_menu m ON m.id=i.menu_id JOIN daqam_orders o ON o.id=i.order_id WHERE DATE(o.order_date) BETWEEN ? AND ? GROUP BY m.id ORDER BY qty DESC LIMIT 5',[$from,$to]);
$trendMap = [];
foreach ($rows as $r) { $k = date('M Y', strtotime($r['order_date'])); $trendMap[$k] = ($trendMap[$k] ?? 0) + (float) $r['total_amount']; }
$trendLabels = array_keys($trendMap); $trendData = array_values($trendMap);

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="laporan-daqam-'.$from.'-sd-'.$to.'.csv"');
    $out=fopen('php://output','w'); fwrite($out,"\xEF\xBB\xBF");
    fputcsv($out,['No Order','Waktu','Tipe','Meja','Metode','Total']);
    foreach($rows as $r) fputcsv($out,[$r['order_no'],tgl($r['order_date'],true),$r['order_type'],$r['table_no'],$r['payment_method'],$r['total_amount']]);
    fputcsv($out,['','','','','TOTAL',$total]); fclose($out); exit;
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div><h1 class="section-title mb-1"><i class="bi bi-file-earmark-bar-graph" style="color:var(--daqam)"></i> Laporan Penjualan Daqam</h1><div class="text-muted small">Rekap order, metode bayar, dan menu terlaris</div></div>
</div>

<div class="card content-card mb-3"><div class="card-body row g-2 align-items-end">
    <div class="col-md-3"><label class="form-label small fw-semibold">Dari</label><input type="date" id="tgl_from" class="form-control form-control-sm" value="<?= e($from) ?>"></div>
    <div class="col-md-3"><label class="form-label small fw-semibold">Sampai</label><input type="date" id="tgl_to" class="form-control form-control-sm" value="<?= e($to) ?>"></div>
    <div class="col-md-auto"><button class="btn btn-sm btn-outline-secondary" data-range="7">7 Hari</button> <button class="btn btn-sm btn-outline-secondary" data-range="30">30 Hari</button> <button class="btn btn-sm btn-primary" id="btnFilter">Tampilkan</button></div>
</div></div>
<script>document.getElementById('btnFilter').onclick=function(){location.href='<?= base_url('/modules/daqam/laporan.php') ?>?from='+document.getElementById('tgl_from').value+'&to='+document.getElementById('tgl_to').value;};</script>

<div class="row g-3 mb-3">
    <div class="col-md-4"><div class="card kpi-card p-3"><span class="kpi-label">TOTAL PENDAPATAN</span><div class="kpi-value" style="color:var(--daqam)"><?= rupiah($total) ?></div></div></div>
    <div class="col-md-4"><div class="card kpi-card p-3"><span class="kpi-label">JUMLAH ORDER</span><div class="kpi-value"><?= angka(count($rows)) ?></div></div></div>
    <div class="col-md-4"><div class="card kpi-card p-3"><span class="kpi-label">RATA-RATA / ORDER</span><div class="kpi-value"><?= rupiah(count($rows)?$total/count($rows):0) ?></div></div></div>
</div>

<div class="card content-card mb-3"><div class="card-body">
    <div class="chart-title"><i class="bi bi-graph-up"></i> Tren Pendapatan pada Rentang Ini</div>
    <div class="chart-box sm"><?= chart('line', $trendLabels, [['label' => 'Pendapatan', 'data' => $trendData, 'color' => '#92400E']]) ?></div>
</div></div>

<div class="row g-3">
    <div class="col-lg-8"><div class="card content-card">
        <div class="card-header d-flex justify-content-between"><span>Detail Order</span><a class="btn btn-sm btn-success" href="<?= base_url('/modules/daqam/laporan.php?from='.$from.'&to='.$to.'&export=csv') ?>"><i class="bi bi-file-earmark-excel"></i> Export Excel/CSV</a></div>
        <div class="table-responsive"><table class="table table-sm mb-0">
            <thead class="table-light"><tr><th>No. Order</th><th>Waktu</th><th>Tipe</th><th>Meja</th><th>Bayar</th><th class="text-end">Total</th></tr></thead>
            <tbody><?php if (!$rows): ?><tr><td colspan="6" class="empty-state">Tidak ada order.</td></tr><?php endif; ?>
            <?php foreach($rows as $r): ?><tr><td class="small"><?= e($r['order_no']) ?></td><td class="small"><?= tgl($r['order_date'],true) ?></td><td class="small"><?= $r['order_type']==='dine_in'?'Dine In':'Take Away' ?></td><td class="small"><?= e($r['table_no'] ?: '-') ?></td><td class="small"><?= e(strtoupper($r['payment_method'])) ?></td><td class="text-end fw-semibold"><?= rupiah($r['total_amount']) ?></td></tr><?php endforeach; ?></tbody>
            <tfoot><tr class="fw-bold"><td colspan="5" class="text-end">TOTAL</td><td class="text-end" style="color:var(--daqam)"><?= rupiah($total) ?></td></tr></tfoot>
        </table></div>
    </div></div>
    <div class="col-lg-4"><div class="card content-card"><div class="card-header">Menu Terlaris</div><div class="list-group list-group-flush">
        <?php if (!$top): ?><div class="list-group-item small text-muted">Belum ada data.</div><?php endif; ?>
        <?php foreach($top as $i=>$t): ?><div class="list-group-item d-flex justify-content-between small"><span><?= $i+1 ?>. <?= e($t['name']) ?></span><span class="badge bg-light text-dark"><?= angka($t['qty']) ?> porsi</span></div><?php endforeach; ?>
    </div></div></div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
