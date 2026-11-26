<?php
require_once __DIR__ . '/../../config/app.php';
require_login();
require_unit('qmas');

$title = 'Transaksi & Distribusi – Qmas';
$activeUnit = 'qmas';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'lunas') {
        $id = (int) ($_POST['id'] ?? 0);
        $method = $_POST['pay_method'] ?? 'tunai';
        if (!in_array($method, ['tunai', 'transfer'], true)) {
            $errors[] = 'Metode pelunasan tidak valid.';
        }
        $bank = payment_bank($method, $_POST['bank'] ?? null);
        if ($method === 'transfer' && $bank === null) {
            $errors[] = 'Bank untuk transfer wajib dipilih (BSI / Muamalat).';
        }
        if (!$errors && $id > 0) {
            run('UPDATE qmas_transactions SET payment_status=?, bank=?, lunas_date=NOW() WHERE id=?', [$method, $bank, $id]);
            activity('qmas', 'Melunasi piutang #' . $id . ' via ' . payment_label($method, $bank));
            flash_set('success', 'Piutang berhasil dilunasi via ' . payment_label($method, $bank) . '.');
            redirect('/modules/qmas/transaksi.php');
        }
    }

    if ($action === 'setor_semua') {
        $bank = payment_bank('transfer', $_POST['bank'] ?? null);
        $tanggal = trim((string) ($_POST['tanggal_setor'] ?? ''));
        if ($bank === null) $errors[] = 'Bank tujuan wajib dipilih (BSI / Muamalat).';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) $errors[] = 'Tanggal setor tidak valid.';
        $jumlah = (int) val('SELECT COUNT(*) FROM qmas_transactions WHERE payment_status="tunai"');
        $totalDana = (float) val('SELECT COALESCE(SUM(total_amount),0) FROM qmas_transactions WHERE payment_status="tunai"');
        if ($jumlah < 1) $errors[] = 'Tidak ada transaksi tunai yang perlu disetor.';
        if (!$errors) {
            run('UPDATE qmas_transactions SET payment_status="transfer", bank=?, setor_date=? WHERE payment_status="tunai"',
                [$bank, $tanggal . ' ' . date('H:i:s')]);
            activity('qmas', 'Setor tunai massal: ' . $jumlah . ' transaksi (' . rupiah($totalDana) . ') ke ' . $bank . ' tanggal ' . $tanggal);
            flash_set('success', $jumlah . ' transaksi tunai (' . rupiah($totalDana) . ') berhasil disetor ke ' . $bank . ' tanggal ' . $tanggal . '.');
            redirect('/modules/qmas/transaksi.php');
        }
    }

    if ($action === 'hapus') {
        $id = (int) ($_POST['id'] ?? 0);
        $typed = strtoupper(clean($_POST['confirm_no'] ?? ''));
        $t = $id > 0 ? row('SELECT * FROM qmas_transactions WHERE id=?', [$id]) : null;

        if (!$t) {
            flash_set('danger', 'Transaksi tidak ditemukan.');
        } elseif ($typed !== strtoupper((string) $t['transaction_no'])) {
            flash_set('danger', 'Nomor transaksi yang diketik tidak sesuai. Hapus dibatalkan.');
        } else {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $isManual = empty($t['import_batch']);

                // Item ikut terhapus otomatis (ON DELETE CASCADE).
                run('DELETE FROM qmas_transactions WHERE id=?', [$id]);
                qmas_recalc_stock();
                $pdo->commit();

                activity('qmas', 'Hapus transaksi ' . $t['transaction_no']
                    . ' (' . rupiah($t['total_amount']) . ' · ' . $t['customer_name'] . ')'
                    . ($isManual ? ' · stok dikembalikan' : ' · transaksi import'));
                flash_set('success', 'Transaksi ' . $t['transaction_no'] . ' berhasil dihapus.'
                    . ($isManual ? ' Stok produk sudah dikembalikan.' : ''));
            } catch (Throwable $e) {
                $pdo->rollBack();
                flash_set('danger', 'Gagal menghapus transaksi: ' . $e->getMessage());
            }
        }
        redirect('/modules/qmas/transaksi.php');
    }

    if ($action === 'simpan') {
        $customer = clean($_POST['customer_name'] ?? '');
        $type = $_POST['customer_type'] ?? 'umum';
        $status = $_POST['payment_status'] ?? 'tunai';
        $pids = $_POST['product_id'] ?? [];
        $qtys = $_POST['quantity'] ?? [];
        if (!is_array($pids)) $pids = [$pids];
        if (!is_array($qtys)) $qtys = [$qtys];

        if ($customer === '') $errors[] = 'Nama pelanggan/unit tujuan wajib diisi.';
        if (!in_array($type, ['fakultas', 'mahasiswa', 'umum'], true)) $type = 'umum';
        if (!in_array($status, ['tunai', 'transfer', 'piutang'], true)) $status = 'tunai';

        $bank = payment_bank($status, $_POST['bank'] ?? null);
        if ($status === 'transfer' && $bank === null) {
            $errors[] = 'Bank untuk transfer wajib dipilih (BSI / Muamalat).';
        }

        $items = [];
        foreach ($pids as $i => $pid) {
            $pid = (int) $pid;
            $qty = (int) ($qtys[$i] ?? 0);
            if ($pid > 0 && $qty > 0) {
                $p = row('SELECT * FROM qmas_products WHERE id=?', [$pid]);
                if (!$p) { $errors[] = 'Produk tidak ditemukan.'; continue; }
                if ($qty > (int) $p['stock']) { $errors[] = 'Stok ' . $p['name'] . ' tidak mencukupi.'; continue; }
                $items[] = ['product' => $p, 'qty' => $qty, 'subtotal' => (float) $p['price'] * $qty];
            }
        }
        if (!$items) $errors[] = 'Tambahkan minimal satu produk dengan jumlah lebih dari 0.';

        if (!$errors) {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $no = transaction_no('QMS', 'qmas_transactions', 'transaction_no');
                $total = 0;
                foreach ($items as $it) $total += $it['subtotal'];

                run('INSERT INTO qmas_transactions (transaction_no,transaction_date,customer_name,customer_type,payment_status,bank,total_amount,user_id,created_at) VALUES (?,NOW(),?,?,?,?,?,?,NOW())',
                    [$no, $customer, $type, $status, $bank, $total, current_user()['id']]);
                $trxId = last_id();

                foreach ($items as $it) {
                    run('INSERT INTO qmas_transaction_items (transaction_id,product_id,quantity,unit_price,subtotal) VALUES (?,?,?,?,?)',
                        [$trxId, $it['product']['id'], $it['qty'], $it['product']['price'], $it['subtotal']]);
                }
                qmas_recalc_stock();
                $pdo->commit();
                activity('qmas', 'Transaksi baru ' . $no . ' (' . rupiah($total) . ' via ' . payment_label($status, $bank) . ')');
                flash_set('success', 'Transaksi ' . $no . ' berhasil disimpan.');
                redirect('/modules/qmas/transaksi.php?detail=' . $trxId);
            } catch (Throwable $e) {
                $pdo->rollBack();
                $errors[] = 'Gagal menyimpan transaksi: ' . $e->getMessage();
            }
        }
    }
}

$detail = null; $detailItems = [];
if (!empty($_GET['detail'])) {
    $detail = row('SELECT * FROM qmas_transactions WHERE id=?', [(int) $_GET['detail']]);
    if ($detail) {
        $detailItems = all('SELECT i.*, p.name product_name, p.size FROM qmas_transaction_items i JOIN qmas_products p ON p.id=i.product_id WHERE i.transaction_id=?', [$detail['id']]);
    }
}

$filterStatus = $_GET['status'] ?? '';
if (!in_array($filterStatus, ['tunai', 'transfer', 'piutang'], true)) $filterStatus = '';
$q = trim(clean($_GET['q'] ?? ''));
$conditions = [];
$params = [];
if ($filterStatus) { $conditions[] = 'payment_status = ?'; $params[] = $filterStatus; }
if ($q !== '')      { $conditions[] = 'customer_name LIKE ?'; $params[] = '%' . $q . '%'; }
$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
$list = all('SELECT * FROM qmas_transactions ' . $where . ' ORDER BY transaction_date DESC LIMIT 50', $params);
$products = all('SELECT * FROM qmas_products WHERE is_active=1 ORDER BY name');

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div><h1 class="section-title mb-1"><i class="bi bi-receipt" style="color:var(--qmas)"></i> Transaksi & Distribusi</h1><div class="text-muted small">Input penjualan cepat & pencatatan pengiriman ke unit/fakultas</div></div>
    <button type="button" class="btn btn-sm btn-outline-info" id="btnSetorSemua"
        data-bs-toggle="modal" data-bs-target="#setorModal"
        <?= $setorJml < 1 ? 'disabled' : '' ?>><i class="bi bi-bank"></i> Setor Semua Tunai</button>
</div>

<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<?php if ($detail): ?>
<div class="card content-card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Detail Transaksi <b><?= e($detail['transaction_no']) ?></b></span>
        <a href="<?= base_url('/modules/qmas/transaksi.php') ?>" class="btn btn-sm btn-outline-secondary">Tutup</a>
    </div>
    <div class="card-body">
        <div class="row small mb-3">
            <div class="col-md-3"><b>Pelanggan:</b> <?= e($detail['customer_name']) ?></div>
            <div class="col-md-3"><b>Jenis:</b> <?= e(ucfirst($detail['customer_type'])) ?></div>
            <div class="col-md-3"><b>Waktu:</b> <?= tgl($detail['transaction_date'], true) ?></div>
            <div class="col-md-3"><b>Status:</b> <span class="badge bg-<?= payment_badge($detail['payment_status']) ?>"><?= e(payment_label($detail['payment_status'], $detail['bank'])) ?></span></div>
        </div>
        <table class="table table-sm">
            <thead class="table-light"><tr><th>Produk</th><th>Ukuran</th><th class="text-end">Qty</th><th class="text-end">Harga</th><th class="text-end">Subtotal</th></tr></thead>
            <tbody><?php foreach ($detailItems as $it): ?><tr>
                <td><?= e($it['product_name']) ?></td><td><?= e($it['size']) ?></td>
                <td class="text-end"><?= angka($it['quantity']) ?></td><td class="text-end"><?= rupiah($it['unit_price']) ?></td><td class="text-end fw-semibold"><?= rupiah($it['subtotal']) ?></td>
            </tr><?php endforeach; ?></tbody>
            <tfoot><tr class="fw-bold"><td colspan="4" class="text-end">TOTAL</td><td class="text-end text-primary"><?= rupiah($detail['total_amount']) ?></td></tr></tfoot>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card content-card">
            <div class="card-header">Input Penjualan Cepat</div>
            <div class="card-body">
                <form method="post" id="formTransaksi">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="simpan">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Nama Pelanggan / Unit Tujuan</label>
                        <input name="customer_name" class="form-control" placeholder="mis. Fakultas Agama Islam" required>
                    </div>
                    <div class="row mb-3">
                        <div class="col-6"><label class="form-label small fw-semibold">Jenis Pelanggan</label>
                            <select name="customer_type" class="form-select"><option value="umum">Umum</option><option value="mahasiswa">Mahasiswa</option><option value="fakultas">Fakultas/Unit</option></select>
                        </div>
                        <div class="col-6"><label class="form-label small fw-semibold">Status Bayar</label>
                            <select name="payment_status" id="paymentStatus" class="form-select">
                                <option value="tunai">Tunai</option>
                                <option value="transfer">Transfer</option>
                                <option value="piutang">Piutang</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3" id="bankGroup" style="display:none">
                        <label class="form-label small fw-semibold">Bank Transfer</label>
                        <select name="bank" id="bankSelect" class="form-select">
                            <option value="">-- Pilih Bank --</option>
                            <option value="BSI">BSI (Bank Syariah Indonesia)</option>
                            <option value="MUAMALAT">Muamalat</option>
                        </select>
                    </div>
                    <label class="form-label small fw-semibold">Item Penjualan</label>
                    <div id="itemRows">
                        <div class="row g-2 item-row mb-2">
                            <div class="col-8"><select name="product_id[]" class="form-select form-select-sm" required><?php foreach ($products as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['name']) ?> (<?= rupiah($p['price']) ?>)</option><?php endforeach; ?></select></div>
                            <div class="col-4"><input type="number" name="quantity[]" class="form-control form-control-sm" min="1" value="1" required></div>
                        </div>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-primary mb-3" id="addRow"><i class="bi bi-plus"></i> Tambah Item</button>
                    <button type="submit" class="btn w-100 text-white fw-bold" style="background:var(--qmas)"><i class="bi bi-check2-circle"></i> Simpan Transaksi</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card content-card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span>Riwayat Transaksi <small class="text-muted fw-normal">(50 terbaru)</small> · <a href="<?= base_url('/modules/qmas/rekap.php') ?>" class="small">Lihat Rekap →</a></span>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <form method="get" class="d-flex gap-1">
                        <?php if ($filterStatus): ?><input type="hidden" name="status" value="<?= e($filterStatus) ?>"><?php endif; ?>
                        <input type="text" name="q" class="form-control form-control-sm" style="width:190px" placeholder="Cari nama pelanggan..." value="<?= e($q) ?>">
                        <button class="btn btn-sm btn-outline-primary" title="Cari"><i class="bi bi-search"></i></button>
                        <?php if ($q !== ''): ?><a href="<?= base_url('/modules/qmas/transaksi.php' . ($filterStatus ? '?status=' . e($filterStatus) : '')) ?>" class="btn btn-sm btn-outline-secondary">Reset</a><?php endif; ?>
                    </form>
                    <div class="btn-group btn-group-sm">
                        <a href="<?= base_url('/modules/qmas/transaksi.php' . ($q !== '' ? '?q=' . urlencode($q) : '')) ?>" class="btn btn-outline-secondary <?= $filterStatus===''?'active':'' ?>">Semua</a>
                        <a href="<?= base_url('/modules/qmas/transaksi.php?status=tunai' . ($q !== '' ? '&q=' . urlencode($q) : '')) ?>" class="btn btn-outline-success <?= $filterStatus==='tunai'?'active':'' ?>">Tunai</a>
                        <a href="<?= base_url('/modules/qmas/transaksi.php?status=transfer' . ($q !== '' ? '&q=' . urlencode($q) : '')) ?>" class="btn btn-outline-primary <?= $filterStatus==='transfer'?'active':'' ?>">Transfer</a>
                        <a href="<?= base_url('/modules/qmas/transaksi.php?status=piutang' . ($q !== '' ? '&q=' . urlencode($q) : '')) ?>" class="btn btn-outline-warning <?= $filterStatus==='piutang'?'active':'' ?>">Piutang</a>
                    </div>
                </div>
            </div>
            <?php if ($q !== ''): ?><div class="card-body py-2 border-bottom small text-muted">Menampilkan <?= count($list) ?> transaksi untuk "<b><?= e($q) ?></b>"<?= $filterStatus ? ' · status ' . e($filterStatus) : '' ?></div><?php endif; ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead class="table-light"><tr><th>No. Transaksi</th><th>Tanggal</th><th>Pelanggan</th><th class="text-end">Total</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php if (!$list): ?><tr><td colspan="6" class="empty-state">Belum ada transaksi.</td></tr><?php endif; ?>
                    <?php foreach ($list as $t): ?>
                        <tr>
                            <td class="small"><?= e($t['transaction_no']) ?></td>
                            <td class="small"><?= tgl($t['transaction_date'], true) ?></td>
                            <td class="small"><?= e($t['customer_name']) ?></td>
                            <td class="text-end fw-semibold"><?= rupiah($t['total_amount']) ?></td>
                            <td><span class="badge bg-<?= payment_badge($t['payment_status']) ?>"><?= e(payment_label($t['payment_status'], $t['bank'])) ?></span></td>
                            <td class="text-nowrap">
                                <a href="<?= base_url('/modules/qmas/transaksi.php?detail='.$t['id']) ?>" class="btn btn-sm btn-outline-primary py-0">Detail</a>
                                <?php if ($t['payment_status'] === 'piutang'): ?>
                                <button type="button" class="btn btn-sm btn-outline-success py-0 btn-lunas" data-id="<?= $t['id'] ?>" data-no="<?= e($t['transaction_no']) ?>">Lunas</button>
                                <?php endif; ?>
                                <button type="button" class="btn btn-sm btn-outline-danger py-0 btn-hapus"
                                    data-id="<?= $t['id'] ?>"
                                    data-no="<?= e($t['transaction_no']) ?>"
                                    data-customer="<?= e($t['customer_name']) ?>"
                                    data-date="<?= e(tgl($t['transaction_date'], true)) ?>"
                                    data-total="<?= e(rupiah($t['total_amount'])) ?>"
                                    data-status="<?= e(payment_label($t['payment_status'], $t['bank'])) ?>"
                                    data-manual="<?= empty($t['import_batch']) ? '1' : '0' ?>"
                                    aria-label="Hapus transaksi <?= e($t['transaction_no']) ?>"><i class="bi bi-trash"></i> Hapus</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal pelunasan piutang -->
<div class="modal fade" id="lunasModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="lunas">
                <input type="hidden" name="id" id="lunasId">
                <div class="modal-header">
                    <h5 class="modal-title">Tandai Lunas</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-light py-2 small mb-3">Transaksi: <b id="lunasNo"></b></div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Metode Pembayaran</label>
                        <select name="pay_method" id="lunasMethod" class="form-select">
                            <option value="tunai">Tunai</option>
                            <option value="transfer">Transfer</option>
                        </select>
                    </div>
                    <div id="lunasBankGroup" style="display:none">
                        <label class="form-label small fw-semibold">Bank Transfer</label>
                        <select name="bank" id="lunasBank" class="form-select">
                            <option value="">-- Pilih Bank --</option>
                            <option value="BSI">BSI (Bank Syariah Indonesia)</option>
                            <option value="MUAMALAT">Muamalat</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-sm text-white fw-bold" style="background:var(--qmas)">Simpan Pelunasan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$setorJml  = (int) val('SELECT COUNT(*) FROM qmas_transactions WHERE payment_status="tunai"');
$setorDana = (float) val('SELECT COALESCE(SUM(total_amount),0) FROM qmas_transactions WHERE payment_status="tunai"');
?>
<!-- Modal setor tunai massal -->
<div class="modal fade" id="setorModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <form method="post">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="setor_semua">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-bank"></i> Setor Semua Tunai ke Bank</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info py-2 small mb-3">
                    <b><?= angka($setorJml) ?> transaksi tunai</b> dengan total <b><?= rupiah($setorDana) ?></b> akan diubah menjadi <b>Transfer</b> sekaligus.
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Bank Tujuan</label>
                    <select name="bank" id="setorBank" class="form-select" required>
                        <option value="">-- Pilih Bank --</option>
                        <option value="BSI">BSI (Bank Syariah Indonesia)</option>
                        <option value="MUAMALAT">Muamalat</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Tanggal Setor</label>
                    <input type="date" name="tanggal_setor" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="form-text small">Semua transaksi berstatus Tunai akan tersetor dalam satu kali proses. Transaksi Transfer &amp; Piutang tidak diubah.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-sm text-white fw-bold" style="background:var(--qmas)" <?= $setorJml < 1 ? 'disabled' : '' ?>><i class="bi bi-check2-circle"></i> Setor Sekarang</button>
            </div>
        </form>
    </div></div>
</div>

<!-- Modal hapus transaksi -->
<div class="modal fade" id="hapusModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="hapus">
                <input type="hidden" name="id" id="hapusId">
                <div class="modal-header">
                    <h5 class="modal-title text-danger"><i class="bi bi-trash"></i> Hapus Transaksi</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger py-2 small">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i> Tindakan ini <b>permanen</b>. Transaksi akan hilang dari omzet, piutang, dan laporan.
                    </div>
                    <table class="table table-sm mb-3">
                        <tbody>
                            <tr><th class="w-25">No. Transaksi</th><td id="hapusNo" class="fw-semibold"></td></tr>
                            <tr><th>Pelanggan</th><td id="hapusCustomer"></td></tr>
                            <tr><th>Waktu</th><td id="hapusDate"></td></tr>
                            <tr><th>Total</th><td id="hapusTotal" class="fw-semibold"></td></tr>
                            <tr><th>Status</th><td id="hapusStatus"></td></tr>
                            <tr><th>Dampak stok</th><td id="hapusDampak"></td></tr>
                        </tbody>
                    </table>
                    <div class="mb-0">
                        <label class="form-label" for="confirmNo">Ketik nomor transaksi <b id="hapusNoText" class="text-danger"></b> untuk konfirmasi</label>
                        <input type="text" id="confirmNo" name="confirm_no" class="form-control" placeholder="Nomor transaksi" autocomplete="off" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-sm btn-danger fw-bold" id="hapusSubmit" disabled><i class="bi bi-trash"></i> Hapus Permanen</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
document.getElementById('addRow').addEventListener('click', function () {
    var wrap = document.getElementById('itemRows');
    var row = wrap.querySelector('.item-row').cloneNode(true);
    row.querySelector('input[name="quantity[]"]').value = 1;
    wrap.appendChild(row);
});

// Tampilkan pilihan bank hanya saat status Transfer (form transaksi baru)
var statusSelect = document.getElementById('paymentStatus');
var bankGroup = document.getElementById('bankGroup');
var bankSelect = document.getElementById('bankSelect');
function toggleBank() {
    var isTransfer = statusSelect.value === 'transfer';
    bankGroup.style.display = isTransfer ? '' : 'none';
    bankSelect.required = isTransfer;
    if (!isTransfer) bankSelect.value = '';
}
statusSelect.addEventListener('change', toggleBank);
toggleBank();

// Modal pelunasan piutang (hanya kalau Bootstrap tersedia)
if (typeof bootstrap !== 'undefined') {
    var lunasModal = new bootstrap.Modal(document.getElementById('lunasModal'));
    document.querySelectorAll('.btn-lunas').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('lunasId').value = btn.dataset.id;
            document.getElementById('lunasNo').textContent = btn.dataset.no;
            document.getElementById('lunasMethod').value = 'tunai';
            document.getElementById('lunasBank').value = '';
            toggleLunasBank();
            lunasModal.show();
        });
    });
}
var lunasMethod = document.getElementById('lunasMethod');
var lunasBankGroup = document.getElementById('lunasBankGroup');
var lunasBank = document.getElementById('lunasBank');
function toggleLunasBank() {
    var isTransfer = lunasMethod.value === 'transfer';
    lunasBankGroup.style.display = isTransfer ? '' : 'none';
    lunasBank.required = isTransfer;
}
lunasMethod.addEventListener('change', toggleLunasBank);

// Modal setor tunai massal dibuka oleh Bootstrap lewat data-bs-target.
// JS ini hanya mereset pilihan bank saat tombol diklik.
var btnSetorSemua = document.getElementById('btnSetorSemua');
if (btnSetorSemua) {
    btnSetorSemua.addEventListener('click', function () {
        document.getElementById('setorBank').value = '';
    });
}

// Modal hapus transaksi: konfirmasi dengan mengetik nomor transaksi
if (typeof bootstrap !== 'undefined') {
    var hapusModal = new bootstrap.Modal(document.getElementById('hapusModal'));
    var confirmNo = document.getElementById('confirmNo');
    var hapusSubmit = document.getElementById('hapusSubmit');
    var hapusNoText = document.getElementById('hapusNoText');

    document.querySelectorAll('.btn-hapus').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('hapusId').value = btn.dataset.id;
            document.getElementById('hapusNo').textContent = btn.dataset.no;
            document.getElementById('hapusCustomer').textContent = btn.dataset.customer;
            document.getElementById('hapusDate').textContent = btn.dataset.date;
            document.getElementById('hapusTotal').textContent = btn.dataset.total;
            document.getElementById('hapusStatus').textContent = btn.dataset.status;
            document.getElementById('hapusDampak').textContent = btn.dataset.manual === '1'
                ? 'Stok produk akan dikembalikan.'
                : 'Transaksi import — stok tidak diubah.';
            hapusNoText.textContent = btn.dataset.no;
            confirmNo.value = '';
            hapusSubmit.disabled = true;
            hapusModal.show();
        });
    });

    confirmNo.addEventListener('input', function () {
        hapusSubmit.disabled = this.value.trim().toUpperCase() !== hapusNoText.textContent.trim().toUpperCase();
    });
}

});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
