<?php
require_once __DIR__ . '/../../config/app.php';
require_login();
require_unit('qmas');

$title = 'Rekap Bulanan – Qmas';
$activeUnit = 'qmas';

$from = $_GET['from'] ?? '2025-01-01';
$to   = $_GET['to'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = '2025-01-01';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to = date('Y-m-d');

$rows = all("SELECT DATE_FORMAT(transaction_date,'%Y-%m') AS periode,
                    COUNT(*) AS trx,
                    COALESCE(SUM(total_amount),0) AS omzet,
                    COALESCE(SUM(CASE WHEN payment_status='tunai' THEN total_amount ELSE 0 END),0) AS tunai,
                    COALESCE(SUM(CASE WHEN payment_status='transfer' THEN total_amount ELSE 0 END),0) AS transfer,
                    COALESCE(SUM(CASE WHEN bank='BSI' THEN total_amount ELSE 0 END),0) AS bsi,
                    COALESCE(SUM(CASE WHEN bank='MUAMALAT' THEN total_amount ELSE 0 END),0) AS muamalat,
                    COALESCE(SUM(CASE WHEN payment_status='piutang' THEN total_amount ELSE 0 END),0) AS piutang
             FROM qmas_transactions
             WHERE DATE(transaction_date) BETWEEN ? AND ?
             GROUP BY DATE_FORMAT(transaction_date,'%Y-%m')
             ORDER BY periode", [$from, $to]);

$labaRows = all("SELECT DATE_FORMAT(t.transaction_date,'%Y-%m') AS periode,
                        COALESCE(SUM(i.subtotal - i.quantity * i.unit_cost),0) AS laba
                 FROM qmas_transaction_items i
                 JOIN qmas_transactions t ON t.id = i.transaction_id
                 WHERE DATE(t.transaction_date) BETWEEN ? AND ?
                 GROUP BY DATE_FORMAT(t.transaction_date,'%Y-%m')", [$from, $to]);
$labaMap = [];
foreach ($labaRows as $l) $labaMap[$l['periode']] = (float) $l['laba'];

$tot = ['trx' => 0, 'omzet' => 0, 'tunai' => 0, 'transfer' => 0, 'bsi' => 0, 'muamalat' => 0, 'piutang' => 0, 'laba' => 0];
foreach ($rows as $r) {
    foreach (['trx', 'omzet', 'tunai', 'transfer', 'bsi', 'muamalat', 'piutang'] as $k) $tot[$k] += (float) $r[$k];
    $tot['laba'] += $labaMap[$r['periode']] ?? 0;
}
$maxOmzet = 0;
$chartLabels = []; $chartOmzet = []; $chartHpp = []; $chartLaba = [];
foreach ($rows as $r) {
    $maxOmzet = max($maxOmzet, (float) $r['omzet']);
    $laba = $labaMap[$r['periode']] ?? 0;
    $chartLabels[] = date('M Y', strtotime($r['periode'] . '-01'));
    $chartOmzet[] = (float) $r['omzet'];
    $chartLaba[] = $laba;
    $chartHpp[] = max(0, (float) $r['omzet'] - $laba);
}

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="rekap-qmas-'.$from.'-sd-'.$to.'.csv"');
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Periode','Jumlah Transaksi','Omzet','Tunai','Transfer','BSI','Muamalat','Piutang Belum Lunas','HPP/Laba']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['periode'], $r['trx'], $r['omzet'], $r['tunai'], $r['transfer'], $r['bsi'], $r['muamalat'], $r['piutang'], $labaMap[$r['periode']] ?? 0]);
    }
    fputcsv($out, ['TOTAL', $tot['trx'], $tot['omzet'], $tot['tunai'], $tot['transfer'], $tot['bsi'], $tot['muamalat'], $tot['piutang'], $tot['laba']]);
    fclose($out); exit;
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div><h1 class="section-title mb-1"><i class="bi bi-calendar3-range" style="color:var(--qmas)"></i> Rekap Transaksi Bulanan</h1><div class="text-muted small">Ringkasan Qmas <?= e($from) ?> s/d <?= e($to) ?></div></div>
    <a href="<?= base_url('/modules/qmas/import.php') ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-file-earmark-arrow-up"></i> Import CSV</a>
</div>

<div class="card content-card mb-3"><div class="card-body row g-2 align-items-end">
    <div class="col-md-3"><label class="form-label small fw-semibold">Dari</label><input type="date" id="tgl_from" class="form-control form-control-sm" value="<?= e($from) ?>"></div>
    <div class="col-md-3"><label class="form-label small fw-semibold">Sampai</label><input type="date" id="tgl_to" class="form-control form-control-sm" value="<?= e($to) ?>"></div>
    <div class="col-md-auto">
        <a href="<?= base_url('/modules/qmas/rekap.php?from=2025-01-01&to=2025-12-31') ?>" class="btn btn-sm btn-outline-secondary">2025</a>
        <a href="<?= base_url('/modules/qmas/rekap.php?from=2026-01-01&to=2026-12-31') ?>" class="btn btn-sm btn-outline-secondary">2026</a>
        <a href="<?= base_url('/modules/qmas/rekap.php?from=2025-01-01&to='.date('Y-m-d')) ?>" class="btn btn-sm btn-outline-secondary">2025 → Sekarang</a>
        <button class="btn btn-sm btn-primary" id="btnFilter">Tampilkan</button>
    </div>
</div></div>
<script>document.getElementById('btnFilter').onclick=function(){location.href='<?= base_url('/modules/qmas/rekap.php') ?>?from='+document.getElementById('tgl_from').value+'&to='+document.getElementById('tgl_to').value;};</script>

<div class="row g-3 mb-3">
    <div class="col-md-3"><div class="card kpi-card p-3"><span class="kpi-label">TOTAL OMZET</span><div class="kpi-value text-primary"><?= rupiah($tot['omzet']) ?></div></div></div>
    <div class="col-md-3"><div class="card kpi-card p-3"><span class="kpi-label">JUMLAH TRANSAKSI</span><div class="kpi-value"><?= angka($tot['trx']) ?></div></div></div>
    <div class="col-md-3"><div class="card kpi-card p-3"><span class="kpi-label">RATA-RATA / BULAN</span><div class="kpi-value"><?= rupiah(count($rows) ? $tot['omzet']/count($rows) : 0) ?></div></div></div>
    <div class="col-md-3"><div class="card kpi-card p-3"><span class="kpi-label">LABA (OMZET − HPP)</span><div class="kpi-value text-success"><?= rupiah($tot['laba']) ?></div></div></div>
</div>

<div class="card content-card mb-3"><div class="card-body">
    <div class="chart-title"><i class="bi bi-bar-chart"></i> Omzet vs HPP vs Laba per Bulan</div>
    <div class="chart-box"><?= chart('bar', $chartLabels, [
        ['label' => 'Omzet', 'data' => $chartOmzet, 'color' => '#0284C7'],
        ['label' => 'HPP', 'data' => $chartHpp, 'color' => '#F59E0B'],
        ['label' => 'Laba', 'data' => $chartLaba, 'color' => '#10B981'],
    ]) ?></div>
</div></div>

<div class="card content-card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Rekap per Bulan</span>
        <a class="btn btn-sm btn-success" href="<?= base_url('/modules/qmas/rekap.php?from='.$from.'&to='.$to.'&export=csv') ?>"><i class="bi bi-file-earmark-excel"></i> Export Rekap CSV</a>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0 align-middle">
            <thead class="table-light"><tr>
                <th>Periode</th><th class="text-end">Trx</th><th class="text-end">Omzet</th>
                <th class="text-end">Tunai</th><th class="text-end">Transfer (BSI / Muamalat)</th>
                <th class="text-end">Piutang</th><th class="text-end">Laba</th><th style="width:160px">Grafik</th>
            </tr></thead>
            <tbody>
            <?php if (!$rows): ?><tr><td colspan="8" class="empty-state">Tidak ada transaksi pada rentang ini.</td></tr><?php endif; ?>
            <?php foreach ($rows as $r): $laba = $labaMap[$r['periode']] ?? 0; ?>
                <tr>
                    <td class="fw-semibold"><?= e(date('F Y', strtotime($r['periode'].'-01'))) ?></td>
                    <td class="text-end"><?= angka($r['trx']) ?></td>
                    <td class="text-end fw-bold"><?= rupiah($r['omzet']) ?></td>
                    <td class="text-end"><?= rupiah($r['tunai']) ?></td>
                    <td class="text-end"><?= rupiah($r['transfer']) ?><div class="small text-muted">BSI <?= rupiah($r['bsi']) ?> · Muamalat <?= rupiah($r['muamalat']) ?></div></td>
                    <td class="text-end"><?= rupiah($r['piutang']) ?></td>
                    <td class="text-end text-success"><?= rupiah($laba) ?></td>
                    <td><div class="progress" style="height:8px"><div class="progress-bar" style="width:<?= $maxOmzet > 0 ? ((float)$r['omzet']/$maxOmzet)*100 : 0 ?>%;background:var(--qmas)"></div></div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr class="fw-bold">
                <td>TOTAL</td><td class="text-end"><?= angka($tot['trx']) ?></td><td class="text-end text-primary"><?= rupiah($tot['omzet']) ?></td>
                <td class="text-end"><?= rupiah($tot['tunai']) ?></td><td class="text-end"><?= rupiah($tot['transfer']) ?></td>
                <td class="text-end"><?= rupiah($tot['piutang']) ?></td><td class="text-end text-success"><?= rupiah($tot['laba']) ?></td><td></td>
            </tr></tfoot>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
