<?php
require_once __DIR__ . '/../../config/app.php';
require_login();
require_unit('qmas');

$title = 'Laporan Keuangan – Qmas';
$activeUnit = 'qmas';

$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to = date('Y-m-d');

$rows = all('SELECT * FROM qmas_transactions WHERE DATE(transaction_date) BETWEEN ? AND ? ORDER BY transaction_date DESC', [$from, $to]);
$sumTotal = 0; $sumLunas = 0; $sumPiutang = 0; $sumTunai = 0; $sumTransfer = 0;
$perBank = ['BSI' => 0, 'MUAMALAT' => 0];
foreach ($rows as $r) {
    $amount = (float) $r['total_amount'];
    $status = qmas_laporan_status($r['payment_status'], $r['transaction_date']);
    $sumTotal += $amount;
    if ($status === 'piutang') {
        $sumPiutang += $amount;
    } else {
        $sumLunas += $amount;
        if ($status === 'transfer') {
            $sumTransfer += $amount;
            if (isset($perBank[$r['bank']])) $perBank[$r['bank']] += $amount;
        } else {
            $sumTunai += $amount;
        }
    }
}
$kinds = all('SELECT p.kind, SUM(i.quantity) qty FROM qmas_transaction_items i JOIN qmas_products p ON p.id=i.product_id JOIN qmas_transactions t ON t.id=i.transaction_id WHERE DATE(t.transaction_date) BETWEEN ? AND ? GROUP BY p.kind', [$from,$to]);
$costs = row('SELECT COALESCE(SUM(i.quantity*i.unit_cost),0) hpp, COALESCE(SUM(i.subtotal - i.quantity*i.unit_cost),0) laba
              FROM qmas_transaction_items i JOIN qmas_transactions t ON t.id=i.transaction_id
              WHERE DATE(t.transaction_date) BETWEEN ? AND ?', [$from,$to]);
$costMap = [];
foreach (all('SELECT transaction_id, SUM(quantity*unit_cost) hpp FROM qmas_transaction_items GROUP BY transaction_id') as $c) {
    $costMap[$c['transaction_id']] = (float) $c['hpp'];
}
$trendMap = [];
foreach ($rows as $r) {
    $k = date('M Y', strtotime($r['transaction_date']));
    $trendMap[$k] = ($trendMap[$k] ?? 0) + (float) $r['total_amount'];
}
$trendLabels = array_keys($trendMap);
$trendData = array_values($trendMap);

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="laporan-qmas-'.$from.'-sd-'.$to.'.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM untuk Excel
    fputcsv($out, ['No Transaksi','Tanggal','Pelanggan','Jenis','Status','Tgl Lunas','Tgl Setor','Total','HPP','Laba']);
    foreach ($rows as $r) {
        $hpp = $costMap[$r['id']] ?? 0;
        fputcsv($out, [
            $r['transaction_no'], tgl($r['transaction_date'], true), $r['customer_name'], $r['customer_type'],
            payment_label(qmas_laporan_status($r['payment_status'], $r['transaction_date']), $r['bank']),
            $r['lunas_date'] ? tgl($r['lunas_date']) : '', $r['setor_date'] ? tgl($r['setor_date']) : '',
            $r['total_amount'], $hpp, (float)$r['total_amount'] - $hpp,
        ]);
    }
    fputcsv($out, ['','','','','','','TOTAL', $sumTotal, $costs['hpp'], $costs['laba']]);
    fclose($out);
    exit;
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div><h1 class="section-title mb-1"><i class="bi bi-file-earmark-bar-graph" style="color:var(--qmas)"></i> Laporan Keuangan Qmas</h1><div class="text-muted small">Rekapitulasi penjualan harian, mingguan, dan bulanan</div></div>
</div>

<div class="card content-card mb-3">
    <div class="card-body row g-2 align-items-end">
        <div class="col-md-3"><label class="form-label small fw-semibold">Dari Tanggal</label><input type="date" id="tgl_from" class="form-control form-control-sm" value="<?= e($from) ?>"></div>
        <div class="col-md-3"><label class="form-label small fw-semibold">Sampai Tanggal</label><input type="date" id="tgl_to" class="form-control form-control-sm" value="<?= e($to) ?>"></div>
        <div class="col-md-auto">
            <button class="btn btn-sm btn-outline-secondary" onclick="location.href='<?= base_url('/modules/qmas/laporan.php') ?>?from=<?= date('Y-m-d') ?>&to=<?= date('Y-m-d') ?>'">Hari Ini</button>
            <button class="btn btn-sm btn-outline-secondary" data-range="7">7 Hari</button>
            <button class="btn btn-sm btn-outline-secondary" data-range="30">30 Hari</button>
            <button class="btn btn-sm btn-outline-secondary" onclick="location.href='<?= base_url('/modules/qmas/laporan.php') ?>?from=<?= date('Y-m-01') ?>&to=<?= date('Y-m-t') ?>'">Bulan Ini</button>
            <button class="btn btn-sm btn-primary" id="btnFilter">Tampilkan</button>
        </div>
    </div>
</div>

<script>
document.getElementById('btnFilter').addEventListener('click', function () {
    location.href = '<?= base_url('/modules/qmas/laporan.php') ?>?from=' + document.getElementById('tgl_from').value + '&to=' + document.getElementById('tgl_to').value;
});
</script>

<div class="row g-3 mb-3 row-cols-1 row-cols-sm-2 row-cols-lg-3 row-cols-xl-6">
    <div class="col"><div class="card kpi-card p-3"><span class="kpi-label">TOTAL OMZET</span><div class="kpi-value text-primary"><?= rupiah($sumTotal) ?></div></div></div>
    <div class="col"><div class="card kpi-card p-3"><span class="kpi-label">JUMLAH TRANSAKSI</span><div class="kpi-value"><?= angka(count($rows)) ?></div></div></div>
    <div class="col"><div class="card kpi-card p-3"><span class="kpi-label">TUNAI</span><div class="kpi-value text-success"><?= rupiah($sumTunai) ?></div><div class="kpi-foot">Mulai <?= date('d M Y', strtotime(LAPORAN_TUNAI_SEJAK)) ?></div></div></div>
    <div class="col"><div class="card kpi-card p-3"><span class="kpi-label">TRANSFER</span><div class="kpi-value text-primary"><?= rupiah($sumTransfer) ?></div><div class="kpi-foot">Termasuk tunai sebelum <?= date('d M Y', strtotime(LAPORAN_TUNAI_SEJAK)) ?></div></div></div>
    <div class="col"><div class="card kpi-card p-3"><span class="kpi-label">PIUTANG</span><div class="kpi-value text-warning"><?= rupiah($sumPiutang) ?></div></div></div>
    <div class="col"><div class="card kpi-card p-3"><span class="kpi-label">LABA (OMZET − HPP)</span><div class="kpi-value text-success"><?= rupiah($costs['laba']) ?></div></div></div>
</div>

<div class="card content-card mb-3"><div class="card-body">
    <div class="chart-title"><i class="bi bi-graph-up"></i> Tren Omzet pada Rentang Ini</div>
    <div class="chart-box sm"><?= chart('line', $trendLabels, [['label' => 'Omzet', 'data' => $trendData, 'color' => '#0284C7']]) ?></div>
</div></div>

<div class="card content-card mb-3">
    <div class="card-header">Ringkasan per Jenis Kemasan</div>
    <div class="card-body d-flex flex-wrap gap-4">
        <?php foreach ($kinds as $k): ?><div><div class="kpi-label"><?= e(strtoupper($k['kind'])) ?></div><div class="h5 mb-0"><?= angka($k['qty']) ?> unit</div></div><?php endforeach; ?>
        <?php if (!$kinds): ?><span class="text-muted small">Tidak ada data pada rentang ini.</span><?php endif; ?>
    </div>
    <div class="card-footer bg-white small text-muted">
        Rincian pelunasan: Tunai <b class="text-dark"><?= rupiah($sumTunai) ?></b> ·
        Transfer <b class="text-primary"><?= rupiah($sumTransfer) ?></b>
        (BSI <?= rupiah($perBank['BSI']) ?>, Muamalat <?= rupiah($perBank['MUAMALAT']) ?>) ·
        Piutang <b class="text-warning"><?= rupiah($sumPiutang) ?></b>
    </div>
</div>

<div class="card content-card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Detail Transaksi (<?= e($from) ?> s/d <?= e($to) ?>)</span>
        <a class="btn btn-sm btn-success" href="<?= base_url('/modules/qmas/laporan.php?from='.$from.'&to='.$to.'&export=csv') ?>"><i class="bi bi-file-earmark-excel"></i> Export Excel/CSV</a>
    </div>
    <div class="table-responsive"><table class="table table-sm table-hover mb-0">
        <thead class="table-light"><tr><th>No. Transaksi</th><th>Tanggal</th><th>Pelanggan</th><th>Jenis</th><th>Status</th><th>Tgl Lunas</th><th>Tgl Setor</th><th class="text-end">Total</th><th class="text-end">HPP</th><th class="text-end">Laba</th></tr></thead>
        <tbody><?php if (!$rows): ?><tr><td colspan="10" class="empty-state">Tidak ada transaksi.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): $hpp = $costMap[$r['id']] ?? 0; $st = qmas_laporan_status($r['payment_status'], $r['transaction_date']); ?><tr>
            <td class="small"><?= e($r['transaction_no']) ?></td><td class="small"><?= tgl($r['transaction_date'], true) ?></td>
            <td class="small"><?= e($r['customer_name']) ?></td><td class="small"><?= e(ucfirst($r['customer_type'])) ?></td>
            <td><span class="badge bg-<?= payment_badge($st) ?>"><?= e(payment_label($st, $r['bank'])) ?></span></td>
            <td class="small"><?= $r['lunas_date'] ? tgl($r['lunas_date']) : '-' ?></td>
            <td class="small"><?= $r['setor_date'] ? tgl($r['setor_date']) : '-' ?></td>
            <td class="text-end fw-semibold"><?= rupiah($r['total_amount']) ?></td>
            <td class="text-end small text-muted"><?= rupiah($hpp) ?></td>
            <td class="text-end small text-success"><?= rupiah((float)$r['total_amount'] - $hpp) ?></td>
        </tr><?php endforeach; ?></tbody>
        <tfoot><tr class="fw-bold"><td colspan="7" class="text-end">TOTAL</td><td class="text-end text-primary"><?= rupiah($sumTotal) ?></td><td class="text-end"><?= rupiah($costs['hpp']) ?></td><td class="text-end text-success"><?= rupiah($costs['laba']) ?></td></tr></tfoot>
    </table></div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
