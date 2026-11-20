<?php
require_once __DIR__ . '/../../config/app.php';
require_login();
require_unit('daqam');

$title = 'Kasir POS – Daqam Caffe';
$activeUnit = 'daqam';
$errors = [];
$receipt = null;
$receiptItems = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $cart = json_decode((string)($_POST['cart_json'] ?? '[]'), true);
    if (!is_array($cart)) $cart = [];
    $type = $_POST['order_type'] ?? 'dine_in';
    if (!in_array($type, ['dine_in','take_away'], true)) $type = 'dine_in';
    $method = $_POST['payment_method'] ?? 'tunai';
    if (!in_array($method, ['tunai','qris'], true)) $method = 'tunai';
    $table = clean($_POST['table_no'] ?? '');
    $notes = clean($_POST['notes'] ?? '');

    $items = [];
    foreach ($cart as $c) {
        $mid = (int)($c['id'] ?? 0); $qty = (int)($c['qty'] ?? 0);
        if ($mid < 1 || $qty < 1) continue;
        $m = row('SELECT * FROM daqam_menu WHERE id=?', [$mid]);
        if (!$m || $m['status'] !== 'tersedia') { $errors[] = 'Menu '.$m['name'].' tidak tersedia.'; continue; }
        $recipes = all('SELECT r.ingredient_id, r.quantity, g.stock, g.name FROM daqam_recipes r JOIN daqam_ingredients g ON g.id=r.ingredient_id WHERE r.menu_id=?', [$mid]);
        foreach ($recipes as $rc) {
            if ((float)$rc['stock'] < (float)$rc['quantity']*$qty) {
                $errors[] = 'Bahan '.$rc['name'].' tidak cukup untuk '.$m['name'].'.';
            }
        }
        $items[] = ['menu'=>$m, 'qty'=>$qty, 'notes'=>clean($c['notes'] ?? ''), 'subtotal'=>(float)$m['price']*$qty, 'recipes'=>$recipes];
    }
    if (!$items) $errors[] = 'Keranjang masih kosong.';

    if (!$errors) {
        $pdo = db(); $pdo->beginTransaction();
        try {
            $no = transaction_no('DQ', 'daqam_orders', 'order_no');
            $total = 0; foreach ($items as $it) $total += $it['subtotal'];
            run('INSERT INTO daqam_orders (order_no,order_date,order_type,table_no,notes,payment_method,total_amount,status,user_id,created_at) VALUES (?,NOW(),?,?,?,?,?,"selesai",?,NOW())', [$no,$type,$type==='dine_in'?$table:null,$notes,$method,$total,current_user()['id']]);
            $orderId = last_id();
            foreach ($items as $it) {
                run('INSERT INTO daqam_order_items (order_id,menu_id,quantity,unit_price,notes,subtotal) VALUES (?,?,?,?,?,?)', [$orderId,$it['menu']['id'],$it['qty'],$it['menu']['price'],$it['notes'],$it['subtotal']]);
                foreach ($it['recipes'] as $rc) {
                    run('UPDATE daqam_ingredients SET stock = stock - ? WHERE id=?', [(float)$rc['quantity']*$it['qty'], $rc['ingredient_id']]);
                }
            }
            $pdo->commit();
            activity('daqam', 'Order baru '.$no.' ('.rupiah($total).')');
            $receipt = ['no'=>$no,'type'=>$type,'table'=>$table,'method'=>$method,'total'=>$total,'time'=>date('d-m-Y H:i')];
            $receiptItems = $items;
            flash_set('success', 'Order '.$no.' berhasil disimpan.');
        } catch (Throwable $e) { $pdo->rollBack(); $errors[] = 'Gagal menyimpan: '.$e->getMessage(); }
    }
}

$menu = all('SELECT m.*, c.name category_name FROM daqam_menu m JOIN daqam_categories c ON c.id=m.category_id ORDER BY c.id, m.id');
$cats = all('SELECT * FROM daqam_categories ORDER BY id');

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div><h1 class="section-title mb-1"><i class="bi bi-cup-hot" style="color:var(--daqam)"></i> Kasir POS Interaktif</h1><div class="text-muted small">Klik menu untuk menambah pesanan · pilih meja/take-away & metode bayar</div></div>
</div>

<?php if ($errors): ?><div class="alert alert-danger"><?= e(implode('<br>', $errors)) ?></div><?php endif; ?>

<?php if ($receipt): ?>
<div class="card content-card mb-3 no-print"><div class="card-header d-flex justify-content-between"><span>Order Berhasil – <?= e($receipt['no']) ?></span><button class="btn btn-sm btn-primary" data-print><i class="bi bi-printer"></i> Cetak Struk</button></div></div>
<div id="struk" class="mb-4">
    <div class="text-center"><div class="fw-bold">DAQAM CAFFE</div><div class="small">BUMK UMGO · <?= e($receipt['time']) ?></div><div class="small"><?= e($receipt['no']) ?></div></div><hr>
    <div class="small"><?= $receipt['type']==='dine_in' ? 'Dine In – Meja '.e($receipt['table']) : 'Take Away' ?> &nbsp;|&nbsp; <?= e(strtoupper($receipt['method'])) ?></div><hr>
    <?php foreach ($receiptItems as $it): ?><div class="d-flex justify-content-between small"><span><?= e($it['menu']['name']).' × '.$it['qty'].($it['notes']?' ('.e($it['notes']).')':'') ?></span><span><?= rupiah($it['subtotal']) ?></span></div><?php endforeach; ?><hr>
    <div class="d-flex justify-content-between fw-bold"><span>TOTAL</span><span><?= rupiah($receipt['total']) ?></span></div>
    <div class="text-center small mt-3">Terima kasih telah memesan</div>
</div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card content-card"><div class="card-body">
            <?php foreach ($cats as $cat):
                $items = array_filter($menu, fn($m) => $m['category_id']==$cat['id']);
                if (!$items) continue; ?>
                <div class="small fw-bold text-muted mb-2 mt-3"><?= e(strtoupper($cat['name'])) ?></div>
                <div class="row g-2"><?php foreach ($items as $m): ?>
                    <div class="col-6 col-md-4">
                        <div class="pos-product h-100 add-menu <?= $m['status']==='habis'?'opacity-50':'' ?>" data-id="<?= $m['id'] ?>" data-name="<?= e($m['name']) ?>" data-price="<?= (float)$m['price'] ?>" data-status="<?= $m['status'] ?>">
                            <div class="text-center fs-2"><?= e($m['icon']) ?></div>
                            <div class="pos-name"><?= e($m['name']) ?></div>
                            <div class="pos-price"><?= rupiah($m['price']) ?></div>
                            <div class="small text-muted"><?= $m['status']==='tersedia'?'Tersedia':'Habis' ?></div>
                        </div>
                    </div>
                <?php endforeach; ?></div>
            <?php endforeach; ?>
        </div></div>
    </div>
    <div class="col-lg-5">
        <div class="card content-card position-sticky" style="top:95px">
            <div class="card-header d-flex justify-content-between"><span>Pesanan</span><span class="badge bg-warning text-dark" id="cartCount">0 item</span></div>
            <div class="card-body">
                <form method="post" id="posForm"><?php csrf_field(); ?><input type="hidden" name="cart_json" id="cartJson" value="[]">
                    <div id="cartItems" class="mb-3" style="min-height:140px"><div class="empty-state">Belum ada pesanan</div></div>
                    <div class="d-flex justify-content-between fw-bold fs-5 mb-3"><span>Total</span><span id="cartTotal" class="text-warning"><?= rupiah(0) ?></span></div>
                    <div class="row g-2 mb-2">
                        <div class="col-6"><label class="form-label small fw-semibold">Tipe Pesanan</label><select name="order_type" id="orderType" class="form-select form-select-sm"><option value="dine_in">Dine In</option><option value="take_away">Take Away</option></select></div>
                        <div class="col-6"><label class="form-label small fw-semibold">No. Meja</label><input name="table_no" id="tableNo" class="form-control form-control-sm" placeholder="T03"></div>
                    </div>
                    <div class="mb-2"><label class="form-label small fw-semibold">Catatan Pesanan</label><input name="notes" class="form-control form-control-sm" placeholder="mis. less sugar, extra ice"></div>
                    <div class="mb-3"><label class="form-label small fw-semibold">Metode Pembayaran</label><select name="payment_method" class="form-select form-select-sm"><option value="tunai">Tunai</option><option value="qris">QRIS</option></select></div>
                    <button type="submit" class="btn w-100 fw-bold text-white" style="background:var(--daqam)" id="btnBayar" disabled><i class="bi bi-check2-circle"></i> Simpan Order</button>
                    <button type="button" class="btn btn-sm btn-outline-danger w-100 mt-2" id="clearCart">Kosongkan</button>
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
        var box = document.getElementById('cartItems'), total = 0, count = 0;
        if (!cart.length) box.innerHTML = '<div class="empty-state">Belum ada pesanan</div>';
        else box.innerHTML = cart.map(function (it, i) { total += it.price*it.qty; count += it.qty;
            return '<div class="cart-row d-flex justify-content-between align-items-center"><div class="small"><b>'+it.name+'</b>'+(it.notes?'<br><em>'+it.notes+'</em>':'')+'<br>'+money(it.price)+' × '+it.qty+'</div><div class="btn-group btn-group-sm"><button type="button" class="btn btn-outline-secondary" data-act="minus" data-i="'+i+'">−</button><button type="button" class="btn btn-outline-secondary" data-act="plus" data-i="'+i+'">+</button><button type="button" class="btn btn-outline-danger" data-act="del" data-i="'+i+'">×</button></div></div>';
        }).join('');
        document.getElementById('cartTotal').textContent = money(total);
        document.getElementById('cartCount').textContent = count+' item';
        document.getElementById('cartJson').value = JSON.stringify(cart);
        document.getElementById('btnBayar').disabled = cart.length === 0;
        box.querySelectorAll('button[data-act]').forEach(function (b) { b.addEventListener('click', function () {
            var i = +b.dataset.i;
            if (b.dataset.act==='plus') cart[i].qty++;
            else if (b.dataset.act==='minus') { cart[i].qty--; if (cart[i].qty<1) cart.splice(i,1); }
            else cart.splice(i,1);
            render();
        }); });
    }
    document.querySelectorAll('.add-menu').forEach(function (el) { el.addEventListener('click', function () {
        if (el.dataset.status !== 'tersedia') { alert('Menu sedang habis.'); return; }
        var note = prompt('Catatan untuk '+el.dataset.name+' (opsional):', '') || '';
        var id = +el.dataset.id;
        var found = cart.find(function(x){ return x.id===id && x.notes===note; });
        if (found) found.qty++; else cart.push({id:id,name:el.dataset.name,price:+el.dataset.price,notes:note,qty:1});
        render();
    }); });
    document.getElementById('orderType').addEventListener('change', function () { document.getElementById('tableNo').disabled = this.value === 'take_away'; });
    document.getElementById('clearCart').addEventListener('click', function () { cart = []; render(); });
    render();
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
