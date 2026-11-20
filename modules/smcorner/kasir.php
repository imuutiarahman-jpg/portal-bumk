<?php
require_once __DIR__ . '/../../config/app.php';
require_login();
require_unit('smcorner');

$title = 'Kasir POS – SM Corner';
$activeUnit = 'smcorner';
$errors = [];
$receipt = null;
$receiptItems = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $cart = json_decode((string)($_POST['cart_json'] ?? '[]'), true);
    if (!is_array($cart)) $cart = [];
    $customer = clean($_POST['customer_name'] ?? 'Umum');
    $method = $_POST['payment_method'] ?? 'tunai';
    if (!in_array($method, ['tunai','qris','transfer'], true)) $method = 'tunai';

    $items = [];
    foreach ($cart as $c) {
        $pid = (int)($c['id'] ?? 0); $qty = (int)($c['qty'] ?? 0);
        if ($pid < 1 || $qty < 1) continue;
        $p = row('SELECT * FROM sm_products WHERE id=?', [$pid]);
        if (!$p) continue;
        if ($qty > (int)$p['stock']) { $errors[] = 'Stok '.$p['name'].' tidak mencukupi.'; continue; }
        $items[] = ['product'=>$p, 'qty'=>$qty, 'subtotal'=>(float)$p['price']*$qty];
    }
    if (!$items) $errors[] = 'Keranjang masih kosong.';

    if (!$errors) {
        $pdo = db(); $pdo->beginTransaction();
        try {
            $no = transaction_no('SMC', 'sm_transactions', 'transaction_no');
            $total = 0; foreach ($items as $it) $total += $it['subtotal'];
            run('INSERT INTO sm_transactions (transaction_no,transaction_date,customer_name,payment_method,total_amount,user_id,created_at) VALUES (?,NOW(),?,?,?,?,NOW())', [$no,$customer,$method,$total,current_user()['id']]);
            $trxId = last_id();
            foreach ($items as $it) {
                run('INSERT INTO sm_transaction_items (transaction_id,product_id,quantity,unit_price,unit_cost,subtotal) VALUES (?,?,?,?,?,?)', [$trxId,$it['product']['id'],$it['qty'],$it['product']['price'],$it['product']['cost'],$it['subtotal']]);
                run('UPDATE sm_products SET stock = stock - ? WHERE id=?', [$it['qty'],$it['product']['id']]);
            }
            $pdo->commit();
            activity('smcorner', 'Transaksi baru '.$no.' ('.rupiah($total).')');
            $receipt = ['no'=>$no, 'customer'=>$customer, 'method'=>$method, 'total'=>$total, 'time'=>date('d-m-Y H:i')];
            $receiptItems = $items;
            flash_set('success', 'Transaksi '.$no.' berhasil.');
        } catch (Throwable $e) { $pdo->rollBack(); $errors[] = 'Gagal menyimpan: '.$e->getMessage(); }
    }
}

$products = all('SELECT * FROM sm_products WHERE stock > 0 ORDER BY name');
$cats = all('SELECT * FROM sm_categories ORDER BY name');

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div><h1 class="section-title mb-1"><i class="bi bi-cart3" style="color:var(--smcorner)"></i> Kasir Point of Sale</h1><div class="text-muted small">Cari barang berdasarkan nama/kode, lalu klik untuk menambah ke keranjang</div></div>
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
    <?php foreach ($receiptItems as $it): ?><div class="d-flex justify-content-between small"><span><?= e($it['product']['name']).' × '.$it['qty'] ?></span><span><?= rupiah($it['subtotal']) ?></span></div><?php endforeach; ?>
    <hr>
    <div class="d-flex justify-content-between fw-bold"><span>TOTAL</span><span><?= rupiah($receipt['total']) ?></span></div>
    <div class="text-center small mt-3">Terima kasih telah berbelanja</div>
</div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card content-card">
            <div class="card-header"><input type="text" id="searchProduct" class="form-control form-control-sm" placeholder="🔍 Cari nama atau kode barang..."></div>
            <div class="card-body">
                <?php foreach ($cats as $cat):
                    $items = array_filter($products, fn($p) => $p['category_id'] == $cat['id']);
                    if (!$items) continue; ?>
                    <div class="small fw-bold text-muted mb-2 mt-3"><?= e(strtoupper($cat['name'])) ?></div>
                    <div class="row g-2">
                        <?php foreach ($items as $p): ?>
                            <div class="col-6 col-md-4">
                                <div class="pos-product h-100 add-product" data-id="<?= $p['id'] ?>" data-name="<?= e($p['name']) ?>" data-price="<?= (float)$p['price'] ?>">
                                    <div class="pos-name"><?= e($p['name']) ?></div>
                                    <div class="small text-muted"><?= e($p['sku']) ?> · <?= e($p['size']) ?></div>
                                    <div class="pos-price mt-2"><?= rupiah($p['price']) ?></div>
                                    <div class="small text-muted">Stok: <?= angka($p['stock']) ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card content-card position-sticky" style="top:95px">
            <div class="card-header d-flex justify-content-between"><span>Keranjang</span><span class="badge bg-success" id="cartCount">0 item</span></div>
            <div class="card-body">
                <form method="post" id="posForm">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="cart_json" id="cartJson" value="[]">
                    <div id="cartItems" class="mb-3" style="min-height:150px"><div class="empty-state">Keranjang masih kosong</div></div>
                    <div class="d-flex justify-content-between fw-bold fs-5 mb-3"><span>Total</span><span id="cartTotal" class="text-success">Rp 0</span></div>
                    <div class="row g-2 mb-3">
                        <div class="col-7"><label class="form-label small fw-semibold">Nama Pelanggan</label><input name="customer_name" class="form-control form-control-sm" value="Umum"></div>
                        <div class="col-5"><label class="form-label small fw-semibold">Metode Bayar</label><select name="payment_method" class="form-select form-select-sm"><option value="tunai">Tunai</option><option value="qris">QRIS</option><option value="transfer">Transfer</option></select></div>
                    </div>
                    <button type="submit" class="btn w-100 fw-bold text-white" style="background:var(--smcorner)" id="btnBayar" disabled><i class="bi bi-check2-circle"></i> Bayar & Simpan</button>
                    <button type="button" class="btn btn-sm btn-outline-danger w-100 mt-2" id="clearCart">Kosongkan Keranjang</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var cart = [];
    function money(v) { return 'Rp ' + Number(v).toLocaleString('id-ID'); }
    function render() {
        var box = document.getElementById('cartItems');
        var total = 0, count = 0;
        if (!cart.length) { box.innerHTML = '<div class="empty-state">Keranjang masih kosong</div>'; }
        else {
            box.innerHTML = cart.map(function (it, i) {
                total += it.price * it.qty; count += it.qty;
                return '<div class="cart-row d-flex justify-content-between align-items-center">' +
                    '<div class="small"><b>' + it.name + '</b><br>' + money(it.price) + ' × ' + it.qty + '</div>' +
                    '<div class="btn-group btn-group-sm"><button type="button" class="btn btn-outline-secondary" data-act="minus" data-i="' + i + '">−</button><button type="button" class="btn btn-outline-secondary" data-act="plus" data-i="' + i + '">+</button><button type="button" class="btn btn-outline-danger" data-act="del" data-i="' + i + '">×</button></div></div>';
            }).join('');
        }
        document.getElementById('cartTotal').textContent = money(total);
        document.getElementById('cartCount').textContent = count + ' item';
        document.getElementById('cartJson').value = JSON.stringify(cart);
        document.getElementById('btnBayar').disabled = cart.length === 0;
        box.querySelectorAll('button[data-act]').forEach(function (b) {
            b.addEventListener('click', function () {
                var i = +b.dataset.i;
                if (b.dataset.act === 'plus') cart[i].qty++;
                else if (b.dataset.act === 'minus') { cart[i].qty--; if (cart[i].qty < 1) cart.splice(i, 1); }
                else cart.splice(i, 1);
                render();
            });
        });
    }
    document.querySelectorAll('.add-product').forEach(function (el) {
        el.addEventListener('click', function () {
            var id = +el.dataset.id;
            var found = cart.find(function (x) { return x.id === id; });
            if (found) found.qty++; else cart.push({ id: id, name: el.dataset.name, price: +el.dataset.price, qty: 1 });
            render();
        });
    });
    document.getElementById('clearCart').addEventListener('click', function () { cart = []; render(); });
    document.getElementById('searchProduct').addEventListener('input', function () {
        var q = this.value.toLowerCase();
        document.querySelectorAll('.add-product').forEach(function (el) {
            el.parentElement.style.display = (el.dataset.name + ' ' + el.querySelector('.small').textContent).toLowerCase().indexOf(q) > -1 ? '' : 'none';
        });
    });
    render();
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
