<?php
require_once __DIR__ . '/../../config/app.php';
require_login();
require_unit('qmas');

$title = 'Setor Semua Tunai – Qmas';
$activeUnit = 'qmas';
$errors = [];

$mode   = $_GET['mode'] ?? ($_POST['mode'] ?? 'semua');
if (!in_array($mode, ['semua', 'rentang'], true)) $mode = 'semua';
$dari   = $_GET['dari'] ?? ($_POST['dari'] ?? '');
$sampai = $_GET['sampai'] ?? ($_POST['sampai'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dari))   $dari = '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sampai)) $sampai = '';

// Filter transaksi tunai yang akan disetor
$conditions = ['payment_status="tunai"'];
$params = [];
if ($mode === 'rentang') {
    if ($dari !== '' && $sampai !== '') {
        $conditions[] = 'DATE(transaction_date) BETWEEN ? AND ?';
        $params[] = $dari; $params[] = $sampai;
    } elseif ($dari !== '') {
        $conditions[] = 'DATE(transaction_date) >= ?'; $params[] = $dari;
    } elseif ($sampai !== '') {
        $conditions[] = 'DATE(transaction_date) <= ?'; $params[] = $sampai;
    }
}
$where = implode(' AND ', $conditions);

$jumlah    = (int) val('SELECT COUNT(*) FROM qmas_transactions WHERE ' . $where, $params);
$totalDana = (float) val('SELECT COALESCE(SUM(total_amount),0) FROM qmas_transactions WHERE ' . $where, $params);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $bank = payment_bank('transfer', $_POST['bank'] ?? null);
    $tanggalSetor = trim((string) ($_POST['tanggal_setor'] ?? ''));
    if ($bank === null) $errors[] = 'Bank tujuan wajib dipilih (BSI / Muamalat).';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggalSetor)) $errors[] = 'Tanggal setor tidak valid.';
    if ($jumlah < 1) $errors[] = 'Tidak ada transaksi tunai pada jangkauan ini.';
    if (!$errors) {
        $updateParams = array_merge([$bank, $tanggalSetor . ' ' . date('H:i:s')], $params);
        run('UPDATE qmas_transactions SET payment_status="transfer", bank=?, setor_date=? WHERE ' . $where, $updateParams);
        activity('qmas', 'Setor tunai: ' . $jumlah . ' transaksi (' . rupiah($totalDana) . ') ke ' . $bank . ' tanggal ' . $tanggalSetor);
        flash_set('success', $jumlah . ' transaksi tunai (' . rupiah($totalDana) . ') berhasil disetor ke ' . $bank . ' tanggal ' . $tanggalSetor . '.');
        redirect('/modules/qmas/transaksi.php');
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>
<!-- qmas-setor-v2-tanpa-modal -->
<div class="page-head">
    <div>
        <div class="section-eyebrow">Unit Usaha · Qmas</div>
        <h1 class="section-title mb-1"><i class="bi bi-bank" style="color:var(--qmas)"></i> Setor Tunai ke Bank</h1>
        <div class="page-sub">Pindahkan transaksi Tunai menjadi Transfer—semua atau sesuai jangkauan tanggal</div>
    </div>
    <a href="<?= base_url('/modules/qmas/transaksi.php') ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Kembali ke Transaksi</a>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card content-card mb-3">
            <div class="card-header">Pilih Transaksi Tunai</div>
            <div class="card-body">
                <form method="get" class="row g-2 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold mb-1">Jangkauan</label>
                        <select name="mode" class="form-select form-select-sm">
                            <option value="semua" <?= $mode === 'semua' ? 'selected' : '' ?>>Semua transaksi tunai</option>
                            <option value="rentang" <?= $mode === 'rentang' ? 'selected' : '' ?>>Berdasarkan tanggal transaksi</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-semibold mb-1">Dari</label>
                        <input type="date" name="dari" class="form-control form-control-sm" value="<?= e($dari) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-semibold mb-1">Sampai</label>
                        <input type="date" name="sampai" class="form-control form-control-sm" value="<?= e($sampai) ?>">
                    </div>
                    <div class="col-md-2">
                        <button class="btn btn-sm btn-outline-primary w-100"><i class="bi bi-funnel"></i> Tampilkan</button>
                    </div>
                </form>
                <div class="form-text small mt-2">Pilih <b>Berdasarkan tanggal transaksi</b> lalu isi Dari/Sampai kalau hanya mau menyetor tunai pada periode tertentu.</div>
            </div>
        </div>

        <div class="card content-card">
            <div class="card-header">Setor ke Bank</div>
            <div class="card-body">
                <div class="alert alert-light py-2 small mb-3">
                    Ditemukan <b><?= angka($jumlah) ?> transaksi tunai</b> dengan total <b><?= rupiah($totalDana) ?></b><?= $mode === 'rentang' ? ' pada jangkauan yang dipilih' : '' ?>.
                </div>
                <?php if ($jumlah < 1): ?>
                    <div class="alert alert-info mb-0">Tidak ada transaksi Tunai pada jangkauan ini.</div>
                <?php else: ?>
                    <form method="post">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="mode" value="<?= e($mode) ?>">
                        <input type="hidden" name="dari" value="<?= e($dari) ?>">
                        <input type="hidden" name="sampai" value="<?= e($sampai) ?>">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold" for="bank">Bank Tujuan</label>
                                <select name="bank" id="bank" class="form-select" required>
                                    <option value="">-- Pilih Bank --</option>
                                    <option value="BSI">BSI (Bank Syariah Indonesia)</option>
                                    <option value="MUAMALAT">Muamalat</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold" for="tanggal_setor">Tanggal Setor</label>
                                <input type="date" name="tanggal_setor" id="tanggal_setor" class="form-control" value="<?= date('Y-m-d') ?>" required>
                            </div>
                        </div>
                        <div class="form-text small my-3">Tanggal Setor adalah tanggal uang masuk ke bank dan akan tampil di kolom <b>Tgl Setor</b> laporan.</div>
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
                <p>Transaksi Tunai yang dipilih akan diubah menjadi <b>Transfer</b> dengan bank dan <b>Tanggal Setor</b> yang Anda pilih—dalam satu kali proses.</p>
                <p class="mb-0">Transaksi Transfer &amp; Piutang tidak diubah. Ringkasan di atas menyesuaikan jangkauan yang dipilih.</p>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
