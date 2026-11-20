<?php
require_once __DIR__ . '/../../config/app.php';
require_login();
require_unit('smcorner');

$title = 'Rekap Bulanan – SM Corner';
$activeUnit = 'smcorner';

$from = $_GET['from'] ?? '2025-01-01';
$to   = $_GET['to'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = '2025-01-01';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to = date('Y-m-d');

$rows = all("SELECT DATE_FORMAT(transaction_date,'%Y-%m') periode, COUNT(*) trx, COALESCE(SUM(total_amount),0) omzet
             FROM sm_transactions WHERE DATE(transaction_date) BETWEEN ? AND ? GROUP BY periode ORDER BY periode", [$from,$to]);
$labaRows = all("SELECT DATE_FORMAT(t.transaction_date,'%Y-%m') periode, COALESCE(SUM(i.subtotal - i.quantity*i.unit_cost),0) laba
                 FROM sm_transaction_items i JOIN sm_transactions t ON t.id=i.transaction_id
                 WHERE DATE(t.transaction_date) BETWEEN ? AND ? GROUP BY periode", [$from,$to]);
$labaMap = [];
foreach ($labaRows as $l) $labaMap[$l['periode']] = (float) $l['laba'];

$tot = ['trx'=>0,'omzet'=>0,'laba'=>0];
$chartLabels = []; $chartOmzet = []; $chartHpp = []; $chartLaba = [];
foreach ($rows as $r) {
    $tot['trx'] += (int) $r['trx'];
    $tot['omzet'] += (float) $r['omzet'];
    $laba = $labaMap[$r['periode']] ?? 0;
    $tot['laba'] += $laba;
    $chartLabels[] = date('M Y', strtotime($r['periode'].'-01'));
    $chartOmzet[] = (float) $r['omzet'];
    $chartLaba[] = $laba;
    $chartHpp[] = max(0, (float) $r['omzet'] - $laba);
}

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="rekap-smcorner-'.$from.'-sd-'.$to.'.csv"');
    $out = fopen('php://output','w'); fwrite($out,"\xEF\xBB\xBF");
    fputcsv($out,['Periode','Jumlah Transaksi','Omzet','HPP','Laba']);
    foreach ($rows as $r) { $laba = $labaMap[$r['periode']] ?? 0; fputcsv($out,[$r['periode'],$r['trx'],$r['omzet'],max(0,(float)$r['omzet']-$laba),$laba]); }
    fputcsv($out,['TOTAL',$tot['trx'],$tot['omzet'],max(0,$tot['omzet']-$tot['laba']),$tot['laba']]); fclose($out); exit;
}

$purchaseMonth = val('SELECT COALESCE(SUM(total_amount),0) FROM sm_purchases WHERE DATE_FORMAT(purchase_date,"%Y-%m")=DATE_FORMAT(CURDATE(),"%Y-%m")');

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-head">
    <div><h1 class="section-title"><i class="bi bi-calendar3-range" style="color:var(--smcorner)"></i> Rekap Transaksi Bulanan</h1><div class="page-sub">Ringkasan SM Corner <?= e($from) ?> s/d <?= e($to) ?></div></div>
    <a href="<?= base_url('/modules/smcorner/pembelian.php') ?>" class="btn btn-sm btn-outline-success"><i class="bi bi-cart-plus"></i> Pembelian</a>
</div>

<div class="card content-card mb-3"><div class="card-body row g-2 align-items-end">
    <div class="col-md-3"><label class="form-label small fw-semibold">Dari</label><input type="date" id="tgl_from" class="form-control form-control-sm" value="<?= e($from) ?>"></div>
    <div class="col-md-3"><label class="form-label small fw-semibold">Sampai</label><input type="date" id="tgl_to" class="form-control form-control-sm" value="<?= e($to) ?>"></div>
    <div class="col-md-auto"><a href="<?= base_url('/modules/smcorner/rekap.php?from=2025-01-01&to=2025-12-31') ?>" class="btn btn-sm btn-outline-secondary">2025</a> <a href="<?= base_url('/modules/smcorner/rekap.php?from=2026-01-01&to=2026-12-31') ?>" class="btn btn-sm btn-outline-secondary">2026</a> <a href="<?= base_url('/modules/smcorner/rekap.php?from=2025-01-01&to='.date('Y-m-d')) ?>" class="btn btn-sm btn-outline-secondary">2025 → Sekarang</a> <button class="btn btn-sm btn-success" id="btnFilter">Tampilkan</button></div>
</div></div>
<script>document.getElementById('btnFilter').onclick=function(){location.href='<?= base_url('/modules/smcorner/rekap.php') ?>?from='+document.getElementById('tgl_from').value+'&to='+document.getElementById('tgl_to').value;};</script>

<div class="row g-3 mb-3">
    <div class="col-md-3"><div class="card kpi-card p-3"><span class="kpi-label">TOTAL OMZET</span><div class="kpi-value text-success"><?= rupiah($tot['omzet']) ?></div></div></div>
    <div class="col-md-3"><div class="card kpi-card p-3"><span class="kpi-label">JUMLAH TRANSAKSI</span><div class="kpi-value"><?= angka($tot['trx']) ?></div></div></div>
    <div class="col-md-3"><div class="card kpi-card p-3"><span class="kpi-label">LABA</span><div class="kpi-value text-success"><?= rupiah($tot['laba']) ?></div></div></div>
    <div class="col-md-3"><div class="card kpi-card p-3"><span class="kpi-label">PEMBELIAN BULAN INI</span><div class="kpi-value"><?= rupiah($purchaseMonth) ?></div></div></div>
</div>

<div class="card content-card mb-3"><div class="card-body">
    <div class="chart-title"><i class="bi bi-bar-chart"></i> Omzet vs HPP vs Laba per Bulan</div>
    <div class="chart-box"><?= chart('bar', $chartLabels, [
        ['label'=>'Omzet','data'=>$chartOmzet,'color'=>'#059669'],
        ['label'=>'HPP','data'=>$chartHpp,'color'=>'#F59E0B'],
        ['label'=>'Laba','data'=>$chartLaba,'color'=>'#10B981'],
    ]) ?></div>
</div></div>

<div class="card content-card">
    <div class="card-header d-flex justify-content-between"><span>Rekap per Bulan</span><a class="btn btn-sm btn-success" href="<?= base_url('/modules/smcorner/rekap.php?from='.$from.'&to='.$to.'&export=csv') ?>"><i class="bi bi-file-earmark-excel"></i> Export CSV</a></div>
    <div class="table-responsive"><table class="table table-sm table-hover mb-0">
        <thead class="table-light"><tr><th>Periode</th><th class="text-end">Trx</th><th class="text-end">Omzet</th><th class="text-end">HPP</th><th class="text-end">Laba</th></tr></thead>
        <tbody><?php if (!$rows): ?><tr><td colspan="5" class="empty-state">Belum ada transaksi pada rentang ini.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): $laba = $labaMap[$r['periode']] ?? 0; ?><tr>
            <td class="fw-semibold"><?= e(date('F Y', strtotime($r['periode'].'-01'))) ?></td><td class="text-end"><?= angka($r['trx']) ?></td>
            <td class="text-end fw-bold"><?= rupiah($r['omzet']) ?></td><td class="text-end"><?= rupiah(max(0,(float)$r['omzet']-$laba)) ?></td><td class="text-end text-success"><?= rupiah($laba) ?></td>
        </tr><?php endforeach; ?></tbody>
        <tfoot><tr class="fw-bold"><td>TOTAL</td><td class="text-end"><?= angka($tot['trx']) ?></td><td class="text-end text-success"><?= rupiah($tot['omzet']) ?></td><td class="text-end"><?= rupiah(max(0,$tot['omzet']-$tot['laba'])) ?></td><td class="text-end text-success"><?= rupiah($tot['laba']) ?></td></tr></tfoot>
    </table></div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
