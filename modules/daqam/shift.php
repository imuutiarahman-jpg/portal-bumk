<?php
require_once __DIR__ . '/../../config/app.php';
require_login();
require_unit('daqam');

$title = 'Laporan Shift Kasir – Daqam';
$activeUnit = 'daqam';

$uid = current_user()['id'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $drawer = (float)($_POST['cash_in_drawer'] ?? 0);
    $cash   = (float)($_POST['cash_system'] ?? 0);
    $qris   = (float)($_POST['qris_total'] ?? 0);
    run('INSERT INTO daqam_shift_closings (user_id,shift_date,cash_in_drawer,cash_system,qris_total,difference,notes,closed_at) VALUES (?,CURDATE(),?,?,?,?,?,NOW())',
        [$uid,$drawer,$cash,$qris,$drawer-$cash-$qris,clean($_POST['notes'] ?? '')]);
    flash_set('success','Laporan shift berhasil disimpan.');
    redirect('/modules/daqam/shift.php');
}

$last = row('SELECT closed_at FROM daqam_shift_closings WHERE user_id=? ORDER BY closed_at DESC LIMIT 1',[$uid]);
$cond = 'user_id=? AND status="selesai"'; $params = [$uid];
if ($last) { $cond .= ' AND order_date > ?'; $params[] = $last['closed_at']; }
$sums = row('SELECT COALESCE(SUM(CASE WHEN payment_method="tunai" THEN total_amount ELSE 0 END),0) cash,
                    COALESCE(SUM(CASE WHEN payment_method="qris" THEN total_amount ELSE 0 END),0) qris,
                    COUNT(*) n FROM daqam_orders WHERE '.$cond, $params);
$closings = all('SELECT s.*, u.full_name FROM daqam_shift_closings s LEFT JOIN users u ON u.id=s.user_id ORDER BY s.closed_at DESC LIMIT 20');

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div><h1 class="section-title mb-1"><i class="bi bi-cash-stack" style="color:var(--daqam)"></i> Laporan Shift Kasir</h1><div class="text-muted small">Rekap tutup kasir: uang laci vs transaksi sistem & QRIS</div></div>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card content-card">
            <div class="card-header">Tutup Shift Sekarang</div>
            <div class="card-body">
                <div class="small text-muted mb-3">Periode: <?= $last ? tgl($last['closed_at'],true) : 'Awal' ?> s/d sekarang · <?= $sums['n'] ?> transaksi</div>
                <form method="post"><?php csrf_field(); ?>
                    <input type="hidden" name="cash_system" value="<?= $sums['cash'] ?>">
                    <input type="hidden" name="qris_total" value="<?= $sums['qris'] ?>">
                    <div class="mb-2"><label class="form-label small fw-semibold">Uang Tunai di Laci</label><input type="number" step="0.01" name="cash_in_drawer" class="form-control" required></div>
                    <div class="row g-2 mb-3">
                        <div class="col-6"><label class="form-label small fw-semibold">Tunai Sistem</label><input class="form-control" value="<?= rupiah($sums['cash']) ?>" disabled></div>
                        <div class="col-6"><label class="form-label small fw-semibold">Total QRIS</label><input class="form-control" value="<?= rupiah($sums['qris']) ?>" disabled></div>
                    </div>
                    <div class="mb-3"><label class="form-label small fw-semibold">Catatan</label><input name="notes" class="form-control form-control-sm" placeholder="mis. Shift sore"></div>
                    <button class="btn w-100 text-white fw-bold" style="background:var(--daqam)"><i class="bi bi-check2-circle"></i> Simpan Laporan Shift</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card content-card">
            <div class="card-header">Riwayat Tutup Shift</div>
            <div class="table-responsive"><table class="table table-sm mb-0">
                <thead class="table-light"><tr><th>Waktu</th><th>Kasir</th><th class="text-end">Laci</th><th class="text-end">Sistem</th><th class="text-end">QRIS</th><th class="text-end">Selisih</th></tr></thead>
                <tbody><?php if (!$closings): ?><tr><td colspan="6" class="empty-state">Belum ada laporan shift.</td></tr><?php endif; ?>
                <?php foreach ($closings as $c): ?><tr>
                    <td class="small"><?= tgl($c['closed_at'],true) ?></td><td class="small"><?= e($c['full_name'] ?? '-') ?></td>
                    <td class="text-end small"><?= rupiah($c['cash_in_drawer']) ?></td><td class="text-end small"><?= rupiah($c['cash_system']) ?></td>
                    <td class="text-end small"><?= rupiah($c['qris_total']) ?></td>
                    <td class="text-end small fw-bold <?= $c['difference']!=0?'text-danger':'text-success' ?>"><?= rupiah($c['difference']) ?></td>
                </tr><?php endforeach; ?></tbody>
            </table></div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
