<?php
require_once __DIR__ . '/../../config/app.php';
require_login();
require_unit('qmas');

$title = 'Pembelian / Stok Masuk – Qmas';
$activeUnit = 'qmas';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $supplier = clean($_POST['supplier_name'] ?? '');
    $invoice  = clean($_POST['invoice_no'] ?? '');
    $notes    = clean($_POST['notes'] ?? '');
    $status   = $_POST['payment_status'] ?? 'tunai';
    $dateRaw  = $_POST['purchase_date'] ?? '';

    if (!in_array($status, ['tunai', 'transfer', 'piutang'], true)) $status = 'tunai';
    $bank = payment_bank($status, $_POST['bank'] ?? null);
    if ($status === 'transfer' && $bank === null) $errors[] = 'Bank untuk transfer wajib dipilih (BSI / Muamalat).';
    if ($supplier === '') $errors[] = 'Nama supplier wajib diisi.';

    $purchaseDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateRaw)
        ? $dateRaw . ' ' . date('H:i:s')
        : date('Y-m-d H:i:s');

    $pids = $_POST['product_id'] ?? [];
    $qtys = $_POST['quantity'] ?? [];
    $prices = $_POST['unit_price'] ?? [];
    $items = []; $seen = [];
    foreach ($pids as $i => $pid) {
        $pid = (int) $pid;
        $qty = (int) ($qtys[$i] ?? 0);
        $price = max(0, (float) ($prices[$i] ?? 0));
        if ($pid < 1 || $qty < 1) continue;
        if (isset($seen[$pid])) { $errors[] = 'Produk tidak boleh duplikat dalam satu nota pembelian.'; break; }
        $seen[$pid] = true;
        $p = row('SELECT * FROM qmas_products WHERE id=? AND is_active=1', [$pid]);
        if (!$p) { $errors[] = 'Produk tidak ditemukan.'; continue; }
        if ($price <= 0) $price = (float) $p['cost'] ?: (float) $p['price'];
        $items[] = ['product' => $p, 'qty' => $qty, 'price' => $price, 'subtotal' => $qty * $price];
    }
    if (!$items) $errors[] = 'Tambahkan minimal satu produk dengan jumlah lebih dari 0.';

    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $no = 'PO-' . date('Ymd') . '-' . str_pad((string) ((int) val('SELECT COUNT(*) FROM qmas_purchases WHERE DATE(created_at)=CURDATE()') + 1), 4, '0', STR_PAD_LEFT);
            $total = 0;
            foreach ($items as $it) $total += $it['subtotal'];

            run('INSERT INTO qmas_purchases (purchase_no,purchase_date,invoice_no,supplier_name,payment_status,bank,total_amount,notes,user_id,created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())',
                [$no, $purchaseDate, $invoice, $supplier, $status, $bank, $total, $notes, current_user()['id']]);
            $purchaseId = last_id();

            foreach ($items as $it) {
                run('INSERT INTO qmas_purchase_items (purchase_id,product_id,quantity,unit_price,subtotal) VALUES (?,?,?,?,?)',
                    [$purchaseId, $it['product']['id'], $it['qty'], $it['price'], $it['subtotal']]);
                run('UPDATE qmas_products SET stock = stock + ?, cost = ? WHERE id=?',
                    [$it['qty'], $it['price'], $it['product']['id']]);
            }
            $pdo->commit();
            activity('qmas', 'Pembelian ' . $no . ' (' . rupiah($total) . ') dari ' . $supplier);
            flash_set('success', 'Pembelian ' . $no . ' berhasil. Stok & HPP diperbarui.');
            redirect('/modules/qmas/pembelian.php?detail=' . $purchaseId);
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = 'Gagal menyimpan pembelian: ' . $e->getMessage();
        }
    }
}

$detail = null; $detailItems = [];
if (!empty($_GET['detail'])) {
    $detail = row('SELECT * FROM qmas_purchases WHERE id=?', [(int) $_GET['detail']]);
    if ($detail) {
        $detailItems = all('SELECT i.*, p.name product_name, p.size FROM qmas_purchase_items i JOIN qmas_products p ON p.id=i.product_id WHERE i.purchase_id=?', [$detail['id']]);
    }
}

$list = all('SELECT * FROM qmas_purchases ORDER BY purchase_date DESC LIMIT 30');
$products = all('SELECT * FROM qmas_products WHERE is_active=1 ORDER BY name');
$today = val('SELECT COALESCE(SUM(total_amount),0) FROM qmas_purchases WHERE DATE(purchase_date)=CURDATE()');
$month = val('SELECT COALESCE(SUM(total_amount),0) FROM qmas_purchases WHERE DATE_FORMAT(purchase_date,"%Y-%m")=DATE_FORMAT(CURDATE(),"%Y-%m")');

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div><h1 class="section-title mb-1"><i class="bi bi-cart-plus-fill" style="color:var(--qmas)"></i> Pembelian / Stok Masuk</h1><div class="text-muted small">Catat pembelian barang — stok dan HPP diperbarui otomatis</div></div>
    <a href="<?= base_url('/modules/qmas/stok.php') ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-box-seam"></i> Lihat Stok</a>
</div>

<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-md-3"><div class="card kpi-card p-3"><span class="kpi-label">PEMBELIAN HARI INI</span><div class="kpi-value text-primary"><?= rupiah($today) ?></div></div></div>
    <div class="col-md-3"><div class="card kpi-card p-3"><span class="kpi-label">PEMBELIAN BULAN INI</span><div class="kpi-value"><?= rupiah($month) ?></div></div></div>
</div>

<?php if ($detail): ?>
<div class="card content-card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center"><span>Detail Pembelian <b><?= e($detail['purchase_no']) ?></b></span><a href="<?= base_url('/modules/qmas/pembelian.php') ?>" class="btn btn-sm btn-outline-secondary">Tutup</a></div>
    <div class="card-body">
        <div class="row small mb-3">
            <div class="col-md-3"><b>Tanggal:</b> <?= tgl($detail['purchase_date'], true) ?></div>
            <div class="col-md-3"><b>Supplier:</b> <?= e($detail['supplier_name']) ?></div>
            <div class="col-md-3"><b>No. Invoice:</b> <?= e($detail['invoice_no'] ?: '-') ?></div>
            <div class="col-md-3"><b>Status:</b> <span class="badge bg-<?= payment_badge($detail['payment_status']) ?>"><?= e(payment_label($detail['payment_status'], $detail['bank'])) ?></span></div>
        </div>
        <table class="table table-sm">
            <thead class="table-light"><tr><th>Produk</th><th class="text-end">Qty</th><th class="text-end">Harga Beli</th><th class="text-end">Subtotal</th></tr></thead>
            <tbody><?php foreach ($detailItems as $it): ?><tr><td><?= e($it['product_name'].' ('.$it['size'].')') ?></td><td class="text-end"><?= angka($it['quantity']) ?></td><td class="text-end"><?= rupiah($it['unit_price']) ?></td><td class="text-end fw-semibold"><?= rupiah($it['subtotal']) ?></td></tr><?php endforeach; ?></tbody>
            <tfoot><tr class="fw-bold"><td colspan="3" class="text-end">TOTAL</td><td class="text-end text-primary"><?= rupiah($detail['total_amount']) ?></td></tr></tfoot>
        </table>
        <?php if ($detail['notes']): ?><div class="small text-muted">Catatan: <?= e($detail['notes']) ?></div><?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card content-card">
            <div class="card-header">Nota Pembelian Baru</div>
            <div class="card-body">
                <form method="post" id="formPembelian">
                    <?php csrf_field(); ?>
                    <div class="row g-2 mb-2">
                        <div class="col-6"><label class="form-label small fw-semibold">Tanggal</label><input type="date" name="purchase_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>"></div>
                        <div class="col-6"><label class="form-label small fw-semibold">No. Invoice</label><input name="invoice_no" class="form-control form-control-sm" placeholder="Opsional"></div>
                    </div>
                    <div class="mb-2"><label class="form-label small fw-semibold">Nama Supplier</label><input name="supplier_name" class="form-control form-control-sm" placeholder="mis. PT Sinar Abadi" required></div>
                    <div class="row g-2 mb-2">
                        <div class="col-6"><label class="form-label small fw-semibold">Status Bayar</label><select name="payment_status" id="payStatus" class="form-select form-select-sm"><option value="tunai">Tunai</option><option value="transfer">Transfer</option><option value="piutang">Piutang</option></select></div>
                        <div class="col-6" id="bankWrap" style="display:none"><label class="form-label small fw-semibold">Bank</label><select name="bank" id="bankSelect" class="form-select form-select-sm"><option value="">-- Pilih Bank --</option><option value="BSI">BSI</option><option value="MUAMALAT">Muamalat</option></select></div>
                    </div>
                    <label class="form-label small fw-semibold">Item Pembelian</label>
                    <div id="itemRows">
                        <div class="row g-2 item-row mb-2">
                            <div class="col-5"><select name="product_id[]" class="form-select form-select-sm" required><?php foreach ($products as $p): ?><option value="<?= $p['id'] ?>" data-cost="<?= (float) $p['cost'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
                            <div class="col-3"><input type="number" name="quantity[]" class="form-control form-control-sm qty" min="1" value="1" required></div>
                            <div class="col-4"><input type="number" name="unit_price[]" class="form-control form-control-sm price" min="0" value="<?= e($products[0]['cost'] ?? 0) ?>" required></div>
                        </div>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-primary mb-2" id="addRow"><i class="bi bi-plus"></i> Tambah Item</button>
                    <div class="mb-3"><label class="form-label small fw-semibold">Catatan</label><input name="notes" class="form-control form-control-sm"></div>
                    <button type="submit" class="btn w-100 text-white fw-bold" style="background:var(--qmas)"><i class="bi bi-check2-circle"></i> Simpan Pembelian</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card content-card">
            <div class="card-header">Riwayat Pembelian</div>
            <div class="table-responsive"><table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light"><tr><th>No. Nota</th><th>Tanggal</th><th>Supplier</th><th class="text-end">Total</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php if (!$list): ?><tr><td colspan="6" class="empty-state">Belum ada pembelian.</td></tr><?php endif; ?>
                <?php foreach ($list as $p): ?>
                    <tr>
                        <td class="small"><?= e($p['purchase_no']) ?></td>
                        <td class="small"><?= tgl($p['purchase_date'], true) ?></td>
                        <td class="small"><?= e($p['supplier_name']) ?></td>
                        <td class="text-end fw-semibold"><?= rupiah($p['total_amount']) ?></td>
                        <td><span class="badge bg-<?= payment_badge($p['payment_status']) ?>"><?= e(payment_label($p['payment_status'], $p['bank'])) ?></span></td>
                        <td><a href="<?= base_url('/modules/qmas/pembelian.php?detail='.$p['id']) ?>" class="btn btn-sm btn-outline-primary py-0">Detail</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var payStatus = document.getElementById('payStatus');
    var bankWrap = document.getElementById('bankWrap');
    var bankSelect = document.getElementById('bankSelect');
    function toggleBank() {
        var isTransfer = payStatus.value === 'transfer';
        bankWrap.style.display = isTransfer ? '' : 'none';
        bankSelect.required = isTransfer;
        if (!isTransfer) bankSelect.value = '';
    }
    payStatus.addEventListener('change', toggleBank);
    toggleBank();

    var wrap = document.getElementById('itemRows');
    document.getElementById('addRow').addEventListener('click', function () {
        var row = wrap.querySelector('.item-row').cloneNode(true);
        row.querySelector('.qty').value = 1;
        var select = row.querySelector('select');
        row.querySelector('.price').value = select.options[select.selectedIndex].dataset.cost || 0;
        wrap.appendChild(row);
    });
    wrap.addEventListener('change', function (e) {
        if (e.target.tagName === 'SELECT') {
            var price = e.target.parentElement.parentElement.querySelector('.price');
            price.value = e.target.options[e.target.selectedIndex].dataset.cost || 0;
        }
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
