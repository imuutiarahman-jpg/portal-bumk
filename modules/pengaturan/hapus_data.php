<?php
require_once __DIR__ . '/../../config/app.php';
require_login();

$user = current_user();
if ($user['role'] !== 'super_admin' && ($user['unit_code'] ?? '') !== 'qmas') {
    http_response_code(403);
    die('<div class="alert alert-danger m-4">Akses ditolak. Hanya Super Admin dan pengguna Qmas yang bisa menghapus data.</div>');
}

$title = 'Hapus Data – Pengaturan';
$activeUnit = 'qmas';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'hapus_bulan') {
        $bulan = clean($_POST['bulan'] ?? '');
        $typed = clean($_POST['confirm_bulan'] ?? '');

        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $bulan)) {
            flash_set('danger', 'Periode bulan tidak valid.');
        } elseif ($typed !== $bulan) {
            flash_set('danger', 'Periode yang diketik tidak sesuai. Hapus dibatalkan.');
        } else {
            $jmlTrx  = (int) val('SELECT COUNT(*) FROM qmas_transactions WHERE DATE_FORMAT(transaction_date,"%Y-%m")=?', [$bulan]);
            $totTrx  = (float) val('SELECT COALESCE(SUM(total_amount),0) FROM qmas_transactions WHERE DATE_FORMAT(transaction_date,"%Y-%m")=?', [$bulan]);
            $jmlBeli = (int) val('SELECT COUNT(*) FROM qmas_purchases WHERE DATE_FORMAT(purchase_date,"%Y-%m")=?', [$bulan]);

            $pdo = db();
            $pdo->beginTransaction();
            try {
                run('DELETE FROM qmas_transactions WHERE DATE_FORMAT(transaction_date,"%Y-%m")=?', [$bulan]);
                run('DELETE FROM qmas_purchases WHERE DATE_FORMAT(purchase_date,"%Y-%m")=?', [$bulan]);
                run('UPDATE qmas_products SET stock=0');
                $pdo->commit();

                activity('qmas', sprintf(
                    'Hapus data Qmas periode %s: %d transaksi (%s), %d pembelian; stok di-set 0',
                    $bulan, $jmlTrx, rupiah($totTrx), $jmlBeli
                ));
                flash_set('success', 'Data periode ' . $bulan . ' dihapus: ' . $jmlTrx
                    . ' transaksi dan ' . $jmlBeli . ' pembelian. Stok semua produk di-set 0.');
            } catch (Throwable $e) {
                $pdo->rollBack();
                flash_set('danger', 'Gagal menghapus data per bulan: ' . $e->getMessage());
            }
        }
        redirect('/modules/pengaturan/hapus_data.php');
    }

    if ($action === 'hapus_tahun') {
        $tahun = clean($_POST['tahun'] ?? '');
        $typed = clean($_POST['confirm_tahun'] ?? '');

        if (!preg_match('/^(19|20)\d{2}$/', $tahun)) {
            flash_set('danger', 'Tahun tidak valid.');
        } elseif ($typed !== $tahun) {
            flash_set('danger', 'Tahun yang diketik tidak sesuai. Hapus dibatalkan.');
        } else {
            $jmlTrx  = (int) val('SELECT COUNT(*) FROM qmas_transactions WHERE YEAR(transaction_date)=?', [$tahun]);
            $totTrx  = (float) val('SELECT COALESCE(SUM(total_amount),0) FROM qmas_transactions WHERE YEAR(transaction_date)=?', [$tahun]);
            $jmlBeli = (int) val('SELECT COUNT(*) FROM qmas_purchases WHERE YEAR(purchase_date)=?', [$tahun]);

            $pdo = db();
            $pdo->beginTransaction();
            try {
                run('DELETE FROM qmas_transactions WHERE YEAR(transaction_date)=?', [$tahun]);
                run('DELETE FROM qmas_purchases WHERE YEAR(purchase_date)=?', [$tahun]);
                run('UPDATE qmas_products SET stock=0');
                $pdo->commit();

                activity('qmas', sprintf(
                    'Hapus data Qmas tahun %s: %d transaksi (%s), %d pembelian; stok di-set 0',
                    $tahun, $jmlTrx, rupiah($totTrx), $jmlBeli
                ));
                flash_set('success', 'Data tahun ' . $tahun . ' dihapus: ' . $jmlTrx
                    . ' transaksi dan ' . $jmlBeli . ' pembelian. Stok semua produk di-set 0.');
            } catch (Throwable $e) {
                $pdo->rollBack();
                flash_set('danger', 'Gagal menghapus data per tahun: ' . $e->getMessage());
            }
        }
        redirect('/modules/pengaturan/hapus_data.php');
    }
}

$summaryByMonth = [];
foreach (all('SELECT DATE_FORMAT(transaction_date,"%Y-%m") periode, COUNT(*) n, COALESCE(SUM(total_amount),0) total
             FROM qmas_transactions GROUP BY periode') as $s) {
    $summaryByMonth[$s['periode']] = ['trx' => (int) $s['n'], 'omzet' => rupiah($s['total']), 'beli' => 0, 'pembelian' => 'Rp 0'];
}
foreach (all('SELECT DATE_FORMAT(purchase_date,"%Y-%m") periode, COUNT(*) n, COALESCE(SUM(total_amount),0) total
             FROM qmas_purchases GROUP BY periode') as $s) {
    $key = $s['periode'];
    if (!isset($summaryByMonth[$key])) $summaryByMonth[$key] = ['trx' => 0, 'omzet' => 'Rp 0', 'beli' => 0, 'pembelian' => 'Rp 0'];
    $summaryByMonth[$key]['beli'] = (int) $s['n'];
    $summaryByMonth[$key]['pembelian'] = rupiah($s['total']);
}

$summaryByYear = [];
foreach (all('SELECT YEAR(transaction_date) periode, COUNT(*) n, COALESCE(SUM(total_amount),0) total
             FROM qmas_transactions GROUP BY periode') as $s) {
    $summaryByYear[(string) $s['periode']] = ['trx' => (int) $s['n'], 'omzet' => rupiah($s['total']), 'beli' => 0, 'pembelian' => 'Rp 0'];
}
foreach (all('SELECT YEAR(purchase_date) periode, COUNT(*) n, COALESCE(SUM(total_amount),0) total
             FROM qmas_purchases GROUP BY periode') as $s) {
    $key = (string) $s['periode'];
    if (!isset($summaryByYear[$key])) $summaryByYear[$key] = ['trx' => 0, 'omzet' => 'Rp 0', 'beli' => 0, 'pembelian' => 'Rp 0'];
    $summaryByYear[$key]['beli'] = (int) $s['n'];
    $summaryByYear[$key]['pembelian'] = rupiah($s['total']);
}
$totalStock = (int) val('SELECT COALESCE(SUM(stock),0) FROM qmas_products');

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-head">
    <div>
        <div class="section-eyebrow">Pengaturan</div>
        <h1 class="section-title"><i class="bi bi-trash3 text-danger"></i> Hapus Data</h1>
        <div class="page-sub">Hapus transaksi & pembelian Qmas per bulan atau per tahun. Stok produk akan di-set 0.</div>
    </div>
    <a href="<?= base_url('/modules/pengaturan/index.php') ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Pengaturan</a>
</div>

<div class="card content-card settings-card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="bi bi-calendar2-x text-danger"></i> Hapus Data per Bulan / Tahun</span>
        <span class="badge bg-light text-dark border">Transaksi · Pembelian · Stok</span>
    </div>
    <div class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label" for="bulanHapus">Periode Bulan</label>
                <input type="month" id="bulanHapus" class="form-control" value="<?= date('Y-m') ?>">
            </div>
            <div class="col-md-6">
                <div class="alert alert-light mb-0 small" id="ringkasanBulan" aria-live="polite">Memuat ringkasan…</div>
            </div>
            <div class="col-md-3">
                <button type="button" class="btn btn-outline-danger w-100" id="btnHapusBulan"><i class="bi bi-trash3"></i> Hapus Data Bulan Ini</button>
            </div>
        </div>
        <hr class="my-3">
        <div class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label" for="tahunHapus">Tahun</label>
                <input type="number" id="tahunHapus" class="form-control" min="2000" max="2100" value="<?= date('Y') ?>">
            </div>
            <div class="col-md-6">
                <div class="alert alert-light mb-0 small" id="ringkasanTahun" aria-live="polite">Memuat ringkasan…</div>
            </div>
            <div class="col-md-3">
                <button type="button" class="btn btn-outline-danger w-100" id="btnHapusTahun"><i class="bi bi-trash3"></i> Hapus Data Tahun Ini</button>
            </div>
        </div>
        <div class="form-text mt-2"><i class="bi bi-info-circle"></i> Menghapus periode akan menghapus seluruh transaksi & pembelian pada periode tersebut dan menyetel stok semua produk Qmas menjadi 0.</div>
    </div>
</div>

<div class="modal fade" id="hapusBulanModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <form method="post">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="hapus_bulan">
            <input type="hidden" name="bulan" id="confirmBulanValue">
            <div class="modal-header">
                <h5 class="modal-title text-danger"><i class="bi bi-calendar2-x"></i> Hapus Data per Bulan</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger py-2 small"><i class="bi bi-exclamation-triangle-fill me-1"></i> Tindakan ini <b>permanen</b>. Transaksi & pembelian periode ini dihapus dan stok semua produk Qmas di-set 0.</div>
                <table class="table table-sm mb-3"><tbody>
                    <tr><th class="w-35">Periode</th><td id="bulanInfo" class="fw-semibold"></td></tr>
                    <tr><th>Jumlah transaksi</th><td id="bulanTrx"></td></tr>
                    <tr><th>Total omzet</th><td id="bulanOmzet"></td></tr>
                    <tr><th>Jumlah pembelian</th><td id="bulanBeli"></td></tr>
                    <tr><th>Total pembelian</th><td id="bulanPembelian"></td></tr>
                    <tr><th>Stok saat ini</th><td id="bulanStok"></td></tr>
                </tbody></table>
                <div class="mb-0">
                    <label class="form-label" for="confirmBulanText">Ketik periode <b id="bulanInfoText" class="text-danger"></b> untuk konfirmasi</label>
                    <input type="text" id="confirmBulanText" name="confirm_bulan" class="form-control" placeholder="YYYY-MM" autocomplete="off" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-sm btn-danger fw-bold" id="hapusBulanSubmit" disabled><i class="bi bi-trash3"></i> Hapus Permanen</button>
            </div>
        </form>
    </div></div>
</div>

<div class="modal fade" id="hapusTahunModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <form method="post">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="hapus_tahun">
            <input type="hidden" name="tahun" id="confirmTahunValue">
            <div class="modal-header">
                <h5 class="modal-title text-danger"><i class="bi bi-calendar3"></i> Hapus Data per Tahun</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger py-2 small"><i class="bi bi-exclamation-triangle-fill me-1"></i> Tindakan ini <b>permanen</b>. Transaksi & pembelian satu tahun penuh dihapus dan stok semua produk Qmas di-set 0.</div>
                <table class="table table-sm mb-3"><tbody>
                    <tr><th class="w-35">Tahun</th><td id="tahunInfo" class="fw-semibold"></td></tr>
                    <tr><th>Jumlah transaksi</th><td id="tahunTrx"></td></tr>
                    <tr><th>Total omzet</th><td id="tahunOmzet"></td></tr>
                    <tr><th>Jumlah pembelian</th><td id="tahunBeli"></td></tr>
                    <tr><th>Total pembelian</th><td id="tahunPembelian"></td></tr>
                    <tr><th>Stok saat ini</th><td id="tahunStok"></td></tr>
                </tbody></table>
                <div class="mb-0">
                    <label class="form-label" for="confirmTahunText">Ketik tahun <b id="tahunInfoText" class="text-danger"></b> untuk konfirmasi</label>
                    <input type="text" id="confirmTahunText" name="confirm_tahun" class="form-control" placeholder="YYYY" autocomplete="off" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-sm btn-danger fw-bold" id="hapusTahunSubmit" disabled><i class="bi bi-trash3"></i> Hapus Permanen</button>
            </div>
        </form>
    </div></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var summaryBulan = <?= json_encode($summaryByMonth, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var summaryTahun = <?= json_encode($summaryByYear, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var stokSekarang = '<?= e(rupiah($totalStock)) ?>';
    var bulanHapus = document.getElementById('bulanHapus');
    var tahunHapus = document.getElementById('tahunHapus');

    function updateBulan() {
        var key = bulanHapus.value || '<?= date('Y-m') ?>';
        var s = summaryBulan[key] || {trx: 0, omzet: 'Rp 0', beli: 0, pembelian: 'Rp 0'};
        document.getElementById('ringkasanBulan').textContent = 'Periode ' + key + ': ' + s.trx + ' transaksi (' + s.omzet + '), ' + s.beli + ' pembelian (' + s.pembelian + '). Stok saat ini ' + stokSekarang + '.';
    }
    function updateTahun() {
        var key = String(tahunHapus.value || '<?= date('Y') ?>');
        var s = summaryTahun[key] || {trx: 0, omzet: 'Rp 0', beli: 0, pembelian: 'Rp 0'};
        document.getElementById('ringkasanTahun').textContent = 'Tahun ' + key + ': ' + s.trx + ' transaksi (' + s.omzet + '), ' + s.beli + ' pembelian (' + s.pembelian + '). Stok saat ini ' + stokSekarang + '.';
    }
    bulanHapus.addEventListener('change', updateBulan);
    tahunHapus.addEventListener('input', updateTahun);
    updateBulan();
    updateTahun();

    if (typeof bootstrap !== 'undefined') {
        var bulanModal = new bootstrap.Modal(document.getElementById('hapusBulanModal'));
        var tahunModal = new bootstrap.Modal(document.getElementById('hapusTahunModal'));

        document.getElementById('btnHapusBulan').addEventListener('click', function () {
            var key = bulanHapus.value;
            if (!/^\d{4}-\d{2}$/.test(key)) { alert('Pilih periode bulan terlebih dahulu.'); return; }
            var s = summaryBulan[key] || {trx: 0, omzet: 'Rp 0', beli: 0, pembelian: 'Rp 0'};
            document.getElementById('confirmBulanValue').value = key;
            document.getElementById('bulanInfo').textContent = key;
            document.getElementById('bulanInfoText').textContent = key;
            document.getElementById('bulanTrx').textContent = s.trx + ' transaksi';
            document.getElementById('bulanOmzet').textContent = s.omzet;
            document.getElementById('bulanBeli').textContent = s.beli + ' nota pembelian';
            document.getElementById('bulanPembelian').textContent = s.pembelian;
            document.getElementById('bulanStok').textContent = stokSekarang + ' → Rp 0';
            document.getElementById('confirmBulanText').value = '';
            document.getElementById('hapusBulanSubmit').disabled = true;
            bulanModal.show();
        });
        document.getElementById('confirmBulanText').addEventListener('input', function () {
            document.getElementById('hapusBulanSubmit').disabled = this.value.trim() !== document.getElementById('bulanInfoText').textContent.trim();
        });

        document.getElementById('btnHapusTahun').addEventListener('click', function () {
            var key = String(tahunHapus.value);
            if (!/^(19|20)\d{2}$/.test(key)) { alert('Isi tahun terlebih dahulu, misal 2025.'); return; }
            var s = summaryTahun[key] || {trx: 0, omzet: 'Rp 0', beli: 0, pembelian: 'Rp 0'};
            document.getElementById('confirmTahunValue').value = key;
            document.getElementById('tahunInfo').textContent = key;
            document.getElementById('tahunInfoText').textContent = key;
            document.getElementById('tahunTrx').textContent = s.trx + ' transaksi';
            document.getElementById('tahunOmzet').textContent = s.omzet;
            document.getElementById('tahunBeli').textContent = s.beli + ' nota pembelian';
            document.getElementById('tahunPembelian').textContent = s.pembelian;
            document.getElementById('tahunStok').textContent = stokSekarang + ' → Rp 0';
            document.getElementById('confirmTahunText').value = '';
            document.getElementById('hapusTahunSubmit').disabled = true;
            tahunModal.show();
        });
        document.getElementById('confirmTahunText').addEventListener('input', function () {
            document.getElementById('hapusTahunSubmit').disabled = this.value.trim() !== document.getElementById('tahunInfoText').textContent.trim();
        });
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
