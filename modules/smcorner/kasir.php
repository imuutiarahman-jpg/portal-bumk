<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../includes/sm_import.php';
require_login();
require_unit('smcorner');
sm_import_ensure_schema();

$title = 'Kasir POS – SM Corner';
$activeUnit = 'smcorner';
$errors = [];
$receipt = null;
$receiptItems = [];

/* Daftar barang yang bisa dijual (produk & varian ukuran).
   Dipakai baik oleh form maupun oleh validasi saat simpan. */
$products = all('SELECT * FROM sm_products WHERE stock > 0 ORDER BY name');
$cats = all('SELECT * FROM sm_categories ORDER BY name');
$catNames = [];
foreach ($cats as $c) $catNames[$c['id']] = $c['name'];
$variantRows = all('SELECT v.*, p.name product_name, p.sku FROM sm_product_variants v JOIN sm_products p ON p.id=v.product_id WHERE v.stock > 0 ORDER BY p.name, v.size_name');
$variantByProduct = [];
foreach ($variantRows as $v) $variantByProduct[$v['product_id']][] = $v;

$saleUnits = [];
foreach ($products as $p) {
    if (!empty($variantByProduct[$p['id']])) {
        foreach ($variantByProduct[$p['id']] as $v) {
            $saleUnits[] = [
                'product_id' => $p['id'], 'variant_id' => $v['id'],
                'name' => $p['name'], 'sku' => $p['sku'], 'size' => $v['size_name'],
                'price' => (float) $v['price'], 'stock' => (int) $v['stock'],
                'cost' => (float) $p['cost'], 'category_id' => $p['category_id'],
            ];
        }
    } else {
        $saleUnits[] = [
            'product_id' => $p['id'], 'variant_id' => 0,
            'name' => $p['name'], 'sku' => $p['sku'], 'size' => $p['size'],
            'price' => (float) $p['price'], 'stock' => (int) $p['stock'],
            'cost' => (float) $p['cost'], 'category_id' => $p['category_id'],
        ];
    }
}

$saleByKey = [];
$grouped = [];
foreach ($saleUnits as $u) {
    $key = $u['product_id'] . '-' . $u['variant_id'];
    $saleByKey[$key] = $u;
    $grouped[$u['category_id']][] = $u;
}

$unitOptions = '<option value="">-- Pilih produk --</option>';
foreach ($cats as $c) {
    if (empty($grouped[$c['id']])) continue;
    $unitOptions .= '<optgroup label="' . e($c['name']) . '">';
    foreach ($grouped[$c['id']] as $u) {
        $key = $u['product_id'] . '-' . $u['variant_id'];
        $label = e($u['name'] . ' · ' . $u['sku'] . ' · ' . $u['size'] . ' (Stok: ' . $u['stock'] . ')');
        $unitOptions .= '<option value="' . $key . '" data-price="' . $u['price'] . '" data-stock="' . $u['stock'] . '">' . $label . '</option>';
    }
    $unitOptions .= '</optgroup>';
}
foreach ($grouped as $cid => $units) {
    if (array_key_exists($cid, $catNames)) continue;
    $unitOptions .= '<optgroup label="Lainnya">';
    foreach ($units as $u) {
        $key = $u['product_id'] . '-' . $u['variant_id'];
        $label = e($u['name'] . ' · ' . $u['sku'] . ' · ' . $u['size'] . ' (Stok: ' . $u['stock'] . ')');
        $unitOptions .= '<option value="' . $key . '" data-price="' . $u['price'] . '" data-stock="' . $u['stock'] . '">' . $label . '</option>';
    }
    $unitOptions .= '</optgroup>';
}

$dateForForm = date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $customer = clean($_POST['customer_name'] ?? 'Umum');
    $method = $_POST['payment_method'] ?? 'tunai';
    if (!in_array($method, ['tunai', 'qris', 'transfer'], true)) $method = 'tunai';

    /* Tanggal transaksi bisa dipilih (termasuk tahun); default hari ini. */
    $dateRaw = trim((string) ($_POST['transaction_date'] ?? ''));
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dateRaw, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        $transactionDate = $dateRaw . ' ' . date('H:i:s');
        $dateForForm = $dateRaw;
    } else {
        $transactionDate = date('Y-m-d H:i:s');
        if ($dateRaw !== '') $errors[] = 'Tanggal transaksi tidak valid. Pilih tanggal yang benar.';
    }

    /* Item penjualan dikirim sebagai array baris: produk|varian, qty, harga. */
    $keys = $_POST['item_key'] ?? [];
    $qtys = $_POST['quantity'] ?? [];
    $prices = $_POST['unit_price'] ?? [];
    if (!is_array($keys)) $keys = [];
    if (!is_array($qtys)) $qtys = [];
    if (!is_array($prices)) $prices = [];

    $items = [];
    foreach ($keys as $i => $key) {
        $key = (string) $key;
        if ($key === '' || !isset($saleByKey[$key])) continue;
        $qty = (int) ($qtys[$i] ?? 0);
        if ($qty < 1) continue;

        $u = $saleByKey[$key];
        $priceRaw = trim((string) ($prices[$i] ?? ''));
        $price = $priceRaw === '' ? $u['price'] : max(0, (float) $priceRaw);

        /* Produk/varian yang sama pada beberapa baris digabung jadi satu item,
           supaya pengecekan stok memakai total jumlah yang diminta. */
        if (isset($items[$key])) {
            $items[$key]['qty'] += $qty;
            $items[$key]['subtotal'] = $items[$key]['price'] * $items[$key]['qty'];
        } else {
            $items[$key] = [
                'product_id' => $u['product_id'], 'variant_id' => $u['variant_id'],
                'name' => $u['name'], 'size' => $u['size'], 'cost' => $u['cost'],
                'price' => $price, 'qty' => $qty, 'subtotal' => $price * $qty, 'stock' => $u['stock'],
            ];
        }
    }
    if (!$items) $errors[] = 'Tambahkan minimal satu produk dengan jumlah lebih dari 0.';
    foreach ($items as $it) {
        if ($it['qty'] > $it['stock']) {
            $errors[] = 'Stok ' . $it['name'] . ' (' . $it['size'] . ') tidak mencukupi. Tersedia: ' . $it['stock'] . ', diminta: ' . $it['qty'] . '.';
        }
    }

    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $no = transaction_no('SMC', 'sm_transactions', 'transaction_no');
            $total = 0;
            foreach ($items as $it) $total += $it['subtotal'];

            run('INSERT INTO sm_transactions (transaction_no,transaction_date,customer_name,payment_method,total_amount,user_id,created_at) VALUES (?,?,?,?,?,?,NOW())',
                [$no, $transactionDate, $customer, $method, $total, current_user()['id']]);
            $trxId = last_id();

            foreach ($items as $it) {
                run('INSERT INTO sm_transaction_items (transaction_id,product_id,variant_id,quantity,unit_price,unit_cost,subtotal) VALUES (?,?,?,?,?,?,?)',
                    [$trxId, $it['product_id'], $it['variant_id'] ?: null, $it['qty'], $it['price'], $it['cost'], $it['subtotal']]);
                if ($it['variant_id']) run('UPDATE sm_product_variants SET stock = stock - ? WHERE id=?', [$it['qty'], $it['variant_id']]);
                run('UPDATE sm_products SET stock = stock - ? WHERE id=?', [$it['qty'], $it['product_id']]);
            }

            $pdo->commit();
            activity('smcorner', 'Transaksi baru ' . $no . ' (' . rupiah($total) . ') tanggal ' . date('d-m-Y', strtotime($transactionDate)));
            $receipt = [
                'no' => $no, 'customer' => $customer, 'method' => $method,
                'total' => $total, 'time' => date('d-m-Y H:i', strtotime($transactionDate)),
            ];
            $receiptItems = array_values($items);
            flash_set('success', 'Transaksi ' . $no . ' berhasil.');
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = 'Gagal menyimpan: ' . $e->getMessage();
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div><h1 class="section-title mb-1"><i class="bi bi-cart3" style="color:var(--smcorner)"></i> Kasir Point of Sale</h1><div class="text-muted small">Isi tanggal transaksi, lalu tambahkan beberapa produk dalam satu penjualan</div></div>
</div>

<?php if ($errors): ?><div class="alert alert-danger"><?= e(implode('<br>', $errors)) ?></div><?php endif; ?>

<?php if ($receipt): ?>
<div class="card content-card mb-3 no-print">
    <div class="card-header d-flex justify-content-between align-items-center"><span>Transaksi Berhasil – <?= e($receipt['no']) ?></span><button class="btn btn-sm btn-primary" data-print><i class="bi bi-printer"></i> Cetak Struk</button></div>
</div>
<div id="struk" class="mb-4">
    <div class="text-center"><div class="fw-bold">SUARA MUHAMMADIYAH CORNER</div><div class="small">BUMK UMGO · <?= e($receipt['time']) ?></div><div class="small"><?= e($receipt['no']) ?></div></div>
    <hr>
    <div class="small">Pelanggan: <?= e($receipt['customer']) ?> &nbsp;|&nbsp; Bayar: <?= e(strtoupper($receipt['method'])) ?></div>
    <hr>
    <?php foreach ($receiptItems as $it): ?><div class="d-flex justify-content-between small"><span><?= e($it['name']) ?> (<?= e($it['size']) ?>) × <?= $it['qty'] ?></span><span><?= rupiah($it['subtotal']) ?></span></div><?php endforeach; ?>
    <hr>
    <div class="d-flex justify-content-between fw-bold"><span>TOTAL</span><span><?= rupiah($receipt['total']) ?></span></div>
    <div class="text-center small mt-3">Terima kasih telah berbelanja</div>
</div>
<?php endif; ?>

<div class="card content-card">
    <div class="card-header d-flex justify-content-between align-items-center"><span>Transaksi Baru</span><span class="badge bg-light text-dark">Multi-produk</span></div>
    <div class="card-body">
        <form method="post" id="posForm">
            <?php csrf_field(); ?>
            <div class="row g-2 mb-3">
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Tanggal Transaksi</label>
                    <input type="date" name="transaction_date" class="form-control form-control-sm" value="<?= e($dateForForm) ?>">
                    <div class="form-text small">Bisa memilih tanggal & tahun lain (backdated).</div>
                </div>
                <div class="col-md-5">
                    <label class="form-label small fw-semibold">Nama Pelanggan</label>
                    <input name="customer_name" class="form-control form-control-sm" value="Umum">
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Metode Bayar</label>
                    <select name="payment_method" class="form-select form-select-sm">
                        <option value="tunai">Tunai</option>
                        <option value="qris">QRIS</option>
                        <option value="transfer">Transfer</option>
                    </select>
                </div>
            </div>

            <label class="form-label small fw-semibold">Item Penjualan</label>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-2">
                    <thead class="table-light"><tr><th>Produk</th><th class="text-center">Qty</th><th class="text-end">Harga</th><th class="text-end">Subtotal</th><th></th></tr></thead>
                    <tbody id="itemRows"></tbody>
                </table>
            </div>
            <button type="button" class="btn btn-sm btn-outline-success mb-3" id="addRow"><i class="bi bi-plus"></i> Tambah Baris</button>

            <div class="d-flex justify-content-between fw-bold fs-5 mb-3"><span>Total</span><span id="cartTotal" class="text-success">Rp 0</span></div>
            <button type="submit" class="btn w-100 fw-bold text-white" style="background:var(--smcorner)" id="btnBayar" disabled><i class="bi bi-check2-circle"></i> Bayar & Simpan</button>
        </form>
    </div>
</div>

<script>
(function () {
    var optionsHtml = <?= json_encode($unitOptions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var wrap = document.getElementById('itemRows');

    function money(v) { return 'Rp ' + Number(v).toLocaleString('id-ID'); }

    function makeRow() {
        var tr = document.createElement('tr');
        tr.innerHTML =
            '<td><select name="item_key[]" class="form-select form-select-sm item-select">' + optionsHtml + '</select></td>' +
            '<td style="width:90px"><input type="number" name="quantity[]" class="form-control form-control-sm qty" min="1" value="1"></td>' +
            '<td style="width:130px"><input type="number" name="unit_price[]" class="form-control form-control-sm price" min="0" step="0.01" value="0"></td>' +
            '<td class="text-end subtotal small">Rp 0</td>' +
            '<td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger remove-row" aria-label="Hapus baris">&times;</button></td>';
        wrap.appendChild(tr);

        var sel = tr.querySelector('.item-select');
        var qty = tr.querySelector('.qty');
        var price = tr.querySelector('.price');
        sel.addEventListener('change', function () {
            var opt = sel.options[sel.selectedIndex];
            price.value = opt && opt.dataset.price ? opt.dataset.price : 0;
            recalc();
        });
        qty.addEventListener('input', recalc);
        price.addEventListener('input', recalc);
        tr.querySelector('.remove-row').addEventListener('click', function () {
            tr.remove();
            if (!wrap.children.length) makeRow();
            recalc();
        });
    }

    function recalc() {
        var total = 0, valid = 0;
        Array.prototype.forEach.call(wrap.querySelectorAll('tr'), function (tr) {
            var sel = tr.querySelector('.item-select');
            var qty = parseInt(tr.querySelector('.qty').value, 10) || 0;
            var priceInput = tr.querySelector('.price').value;
            var opt = sel.options[sel.selectedIndex];
            var price = priceInput === '' ? (opt && opt.dataset.price ? Number(opt.dataset.price) : 0) : Number(priceInput);
            var sub = (sel.value && qty > 0) ? price * qty : 0;
            tr.querySelector('.subtotal').textContent = money(sub);
            total += sub;
            if (sel.value && qty > 0) valid++;
        });
        document.getElementById('cartTotal').textContent = money(total);
        document.getElementById('btnBayar').disabled = valid === 0;
    }

    document.getElementById('addRow').addEventListener('click', function () { makeRow(); recalc(); });
    makeRow();
    recalc();
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
