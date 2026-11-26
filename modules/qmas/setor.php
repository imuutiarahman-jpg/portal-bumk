<?php
require_once __DIR__ . '/../../config/app.php';
require_login();
require_unit('qmas');

$title = 'Setor Semua Tunai – Qmas';
$activeUnit = 'qmas';
$errors = [];

$jumlah    = (int) val('SELECT COUNT(*) FROM qmas_transactions WHERE payment_status="tunai"');
$totalDana = (float) val('SELECT COALESCE(SUM(total_amount),0) FROM qmas_transactions WHERE payment_status="tunai"');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $bank = payment_bank('transfer', $_POST['bank'] ?? null);
    $tanggal = trim((string) ($_POST['tanggal_setor'] ?? ''));
    if ($bank === null) $errors[] = 'Bank tujuan wajib dipilih (BSI / Muamalat).';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) $errors[] = 'Tanggal setor tidak valid.';
    if ($jumlah < 1) $errors[] = 'Tidak ada transaksi tunai yang perlu disetor.';
    if (!$errors) {
        run('UPDATE qmas_transactions SET payment_status="transfer", bank=?, setor_date=? WHERE payment_status="tunai"',
            [$bank, $tanggal . ' ' . date('H:i:s')]);
        activity('qmas', 'Setor tunai massal: ' . $jumlah . ' transaksi (' . rupiah($totalDana) . ') ke ' . $bank . ' tanggal ' . $tanggal);
        flash_set('success', $jumlah . ' transaksi tunai (' . rupiah($totalDana) . ') berhasil disetor ke ' . $bank . ' tanggal ' . $tanggal . '.');
        redirect('/modules/qmas/transaksi.php');
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>
<!-- qmas-setor-v1-tanpa-modal -->
<div class="page-head">
    <div>
        <div class="section-eyebrow">Unit Usaha · Qmas</div>
        <h1 class="section-title mb-1"><i class="bi bi-bank" style="color:var(--qmas)"></i> Setor Semua Tunai ke Bank</h1>
        <div class="page-sub">Pindahkan seluruh transaksi Tunai menjadi Transfer sekaligus</div>
    </div>
    <a href="<?= base_url('/modules/qmas/transaksi.php') ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Kembali ke Transaksi</a>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card content-card">
            <div class="card-header">Ringkasan Penyetoran</div>
            <div class="card-body">
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <div class="kpi-label">Jumlah transaksi tunai</div>
                        <div class="h2 mb-0"><?= angka($jumlah) ?> <small class="fs-6 text-muted">transaksi</small></div>
                    </div>
                    <div class="col-md-6">
                        <div class="kpi-label">Total dana tunai</div>
                        <div class="h2 mb-0 text-success"><?= rupiah($totalDana) ?></div>
                    </div>
                </div>

                <?php if ($jumlah < 1): ?>
                    <div class="alert alert-info mb-0">
                        <i class="bi bi-info-circle me-1"></i> Tidak ada transaksi berstatus <b>Tunai</b>. Semua transaksi mungkin sudah disetor atau berstatus Transfer/Piutang.
                    </div>
                <?php else: ?>
                    <form method="post">
                        <?php csrf_field(); ?>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold" for="bank">Bank Tujuan</label>
                            <select name="bank" id="bank" class="form-select" required>
                                <option value="">-- Pilih Bank --</option>
                                <option value="BSI">BSI (Bank Syariah Indonesia)</option>
                                <option value="MUAMALAT">Muamalat</option>
                            </select>
                        </div>
                        <div class="mb-4">
                            <label class="form-label small fw-semibold" for="tanggal_setor">Tanggal Setor</label>
                            <input type="date" name="tanggal_setor" id="tanggal_setor" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn text-white fw-bold" style="background:var(--qmas)"><i class="bi bi-check2-circle"></i> Setor Sekarang</button>
                            <a href="<?= base_url('/modules/qmas/transaksi.php') ?>" class="btn btn-outline-secondary">Batal</a>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card content-card">
            <div class="card-header">Penjelasan</div>
            <div class="card-body small">
                <p>Semua transaksi berstatus <b>Tunai</b> akan diubah menjadi <b>Transfer</b> dengan bank dan tanggal setor yang Anda pilih—dalam satu kali proses.</p>
                <p class="mb-0">Transaksi yang sudah berstatus <b>Transfer</b> atau <b>Piutang</b> tidak diubah. Kolom <b>Tgl Setor</b> di laporan akan otomatis terisi.</p>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
