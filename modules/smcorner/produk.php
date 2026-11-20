<?php
require_once __DIR__ . '/../../config/app.php';
require_login();
require_unit('smcorner');

$title = 'Katalog & Stok – SM Corner';
$activeUnit = 'smcorner';
$errors = [];

function sm_product_fields(array $post): array
{
    return [
        'name'      => clean($post['name'] ?? ''),
        'sku'       => strtoupper(clean($post['sku'] ?? '')),
        'category'  => (int) ($post['category_id'] ?? 0),
        'size'      => clean($post['size'] ?? '-'),
        'price'     => max(0, (float) ($post['price'] ?? 0)),
        'cost'      => max(0, (float) ($post['cost'] ?? 0)),
        'stock'     => max(0, (int) ($post['stock'] ?? 0)),
        'min_stock' => max(0, (int) ($post['min_stock'] ?? 0)),
        'unit'      => clean($post['unit'] ?? 'pcs'),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'kategori') {
        $name = clean($_POST['name'] ?? '');
        if ($name === '') $errors[] = 'Nama kategori wajib diisi.';
        if (!$errors) {
            run('INSERT INTO sm_categories (name,description) VALUES (?,?)', [$name, clean($_POST['description'] ?? '')]);
            flash_set('success', 'Kategori ditambahkan.');
        }
        redirect('/modules/smcorner/produk.php');
    }

    if ($action === 'tambah') {
        $f = sm_product_fields($_POST);
        $errs = [];
        if ($f['name'] === '' || $f['sku'] === '' || $f['category'] < 1) $errs[] = 'Nama, SKU, dan kategori wajib diisi.';
        if (!$errs) {
            try {
                run('INSERT INTO sm_products (category_id,name,sku,size,price,cost,stock,min_stock,unit) VALUES (?,?,?,?,?,?,?,?,?)',
                    [$f['category'], $f['name'], $f['sku'], $f['size'], $f['price'], $f['cost'], $f['stock'], $f['min_stock'], $f['unit']]);
                activity('smcorner', 'Tambah produk ' . $f['name']);
                flash_set('success', 'Produk ' . $f['name'] . ' ditambahkan.');
            } catch (Throwable $e) { $errs[] = 'SKU sudah digunakan.'; }
        }
        foreach ($errs as $err) flash_set('danger', $err);
        redirect('/modules/smcorner/produk.php');
    }

    if ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $f = sm_product_fields($_POST);
        $errs = [];
        if ($id < 1) $errs[] = 'Produk tidak ditemukan.';
        if ($f['name'] === '' || $f['sku'] === '' || $f['category'] < 1) $errs[] = 'Nama, SKU, dan kategori wajib diisi.';
        if (!$errs && val('SELECT COUNT(*) FROM sm_products WHERE sku=? AND id<>?', [$f['sku'], $id]) > 0) $errs[] = 'SKU sudah digunakan produk lain.';
        if (!$errs) {
            try {
                run('UPDATE sm_products SET category_id=?,name=?,sku=?,size=?,price=?,cost=?,stock=?,min_stock=?,unit=? WHERE id=?',
                    [$f['category'], $f['name'], $f['sku'], $f['size'], $f['price'], $f['cost'], $f['stock'], $f['min_stock'], $f['unit'], $id]);
                activity('smcorner', 'Ubah produk #' . $id . ' (' . $f['name'] . ')');
                flash_set('success', 'Produk ' . $f['name'] . ' diperbarui.');
            } catch (Throwable $e) { $errs[] = 'Gagal menyimpan: ' . $e->getMessage(); }
        }
        foreach ($errs as $err) flash_set('danger', $err);
        redirect('/modules/smcorner/produk.php');
    }

    if ($action === 'hapus') {
        $id = (int) ($_POST['id'] ?? 0);
        $typed = clean($_POST['confirm_name'] ?? '');
        $p = row('SELECT * FROM sm_products WHERE id=?', [$id]);
        if (!$p) {
            flash_set('danger', 'Produk tidak ditemukan.');
        } elseif ($typed !== $p['name']) {
            flash_set('danger', 'Nama yang diketik tidak sesuai. Hapus dibatalkan.');
        } else {
            $used = (int) val('SELECT COUNT(*) FROM sm_transaction_items WHERE product_id=?', [$id])
                  + (int) val('SELECT COUNT(*) FROM sm_purchase_items WHERE product_id=?', [$id]);
            if ($used > 0) {
                flash_set('danger', 'Produk sudah dipakai ' . $used . ' transaksi dan tidak bisa dihapus.');
            } else {
                try {
                    run('DELETE FROM sm_products WHERE id=?', [$id]);
                    activity('smcorner', 'Hapus permanen produk #' . $id . ' (' . $p['name'] . ')');
                    flash_set('success', 'Produk ' . $p['name'] . ' dihapus permanen.');
                } catch (Throwable $e) {
                    flash_set('danger', 'Gagal menghapus: ' . $e->getMessage());
                }
            }
        }
        redirect('/modules/smcorner/produk.php');
    }

    if ($action === 'setstock') {
        run('UPDATE sm_products SET stock=? WHERE id=?', [max(0, (int) ($_POST['stock'] ?? 0)), (int) $_POST['id']]);
        flash_set('success', 'Stok diperbarui.');
        redirect('/modules/smcorner/produk.php');
    }
}

$products = all('SELECT p.*, c.name category_name FROM sm_products p JOIN sm_categories c ON c.id=p.category_id ORDER BY c.name, p.name');
$categories = all('SELECT * FROM sm_categories ORDER BY name');
$inventory = val('SELECT COALESCE(SUM(stock*price),0) FROM sm_products');
$hppValue = val('SELECT COALESCE(SUM(stock*cost),0) FROM sm_products');

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-head">
    <div><h1 class="section-title"><i class="bi bi-boxes" style="color:var(--smcorner)"></i> Katalog & Manajemen Stok</h1><div class="page-sub">Nilai inventaris: <b><?= rupiah($inventory) ?></b> · Nilai HPP: <?= rupiah($hppValue) ?> · SKU ukuran S–XXXL / meteran</div></div>
    <a href="<?= base_url('/modules/smcorner/pembelian.php') ?>" class="btn btn-sm btn-outline-success"><i class="bi bi-cart-plus"></i> Pembelian</a>
</div>

<?php if ($errors): ?><div class="alert alert-danger"><?= e(implode('<br>', $errors)) ?></div><?php endif; ?>

<div class="row g-3">
    <div class="col-lg-9">
        <div class="card content-card">
            <div class="card-header">Daftar Produk</div>
            <div class="table-responsive"><table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light"><tr><th>Produk</th><th>SKU</th><th>Kategori</th><th>Ukuran</th><th class="text-end">Harga</th><th class="text-end">HPP</th><th class="text-end">Stok</th><th>Aksi</th></tr></thead>
                <tbody><?php foreach ($products as $p):
                    $used = (int) val('SELECT COUNT(*) FROM sm_transaction_items WHERE product_id=?', [$p['id']]) + (int) val('SELECT COUNT(*) FROM sm_purchase_items WHERE product_id=?', [$p['id']]);
                ?><tr>
                    <td class="small fw-semibold"><?= e($p['name']) ?></td><td class="small"><?= e($p['sku']) ?></td>
                    <td class="small"><?= e($p['category_name']) ?></td><td class="small"><?= e($p['size']) ?></td>
                    <td class="text-end small"><?= rupiah($p['price']) ?></td>
                    <td class="text-end small"><?= rupiah($p['cost']) ?></td>
                    <td class="text-end"><span class="badge bg-<?= $p['stock'] <= $p['min_stock'] ? 'danger' : 'success' ?>"><?= angka($p['stock']).' '.e($p['unit']) ?></span></td>
                    <td class="text-nowrap">
                        <form method="post" class="d-inline-flex gap-1"><?php csrf_field(); ?><input type="hidden" name="action" value="setstock"><input type="hidden" name="id" value="<?= $p['id'] ?>"><input type="number" name="stock" value="<?= $p['stock'] ?>" class="form-control form-control-sm" style="width:70px"><button class="btn btn-sm btn-outline-primary">Set</button></form>
                        <button type="button" class="btn btn-sm btn-outline-warning btn-edit" data-id="<?= $p['id'] ?>"><i class="bi bi-pencil"></i> Ubah</button>
                        <button type="button" class="btn btn-sm btn-outline-danger btn-hapus" data-id="<?= $p['id'] ?>" data-name="<?= e($p['name']) ?>" data-used="<?= $used ?>"><i class="bi bi-trash"></i> Hapus</button>
                    </td>
                </tr><?php endforeach; ?></tbody>
            </table></div>
        </div>
    </div>
    <div class="col-lg-3">
        <div class="card content-card mb-3">
            <div class="card-header">Tambah Produk</div>
            <div class="card-body">
                <form method="post"><?php csrf_field(); ?><input type="hidden" name="action" value="tambah">
                    <div class="mb-2"><label class="form-label small fw-semibold">Nama Produk</label><input name="name" class="form-control form-control-sm" required></div>
                    <div class="mb-2"><label class="form-label small fw-semibold">SKU</label><input name="sku" class="form-control form-control-sm" required></div>
                    <div class="mb-2"><label class="form-label small fw-semibold">Kategori</label><select name="category_id" class="form-select form-select-sm"><?php foreach ($categories as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
                    <div class="row g-2 mb-2"><div class="col-6"><label class="form-label small fw-semibold">Ukuran</label><input name="size" class="form-control form-control-sm" placeholder="L / meter"></div><div class="col-6"><label class="form-label small fw-semibold">Satuan</label><input name="unit" class="form-control form-control-sm" value="pcs"></div></div>
                    <div class="row g-2 mb-2"><div class="col-6"><label class="form-label small fw-semibold">Harga</label><input type="number" name="price" class="form-control form-control-sm" value="0"></div><div class="col-6"><label class="form-label small fw-semibold">HPP</label><input type="number" name="cost" class="form-control form-control-sm" value="0"></div></div>
                    <div class="row g-2 mb-3"><div class="col-6"><label class="form-label small fw-semibold">Stok</label><input type="number" name="stock" class="form-control form-control-sm" value="0"></div><div class="col-6"><label class="form-label small fw-semibold">Min.</label><input type="number" name="min_stock" class="form-control form-control-sm" value="0"></div></div>
                    <button class="btn btn-sm w-100 text-white fw-bold" style="background:var(--smcorner)">Simpan Produk</button>
                </form>
            </div>
        </div>
        <div class="card content-card"><div class="card-header">Tambah Kategori</div><div class="card-body"><form method="post" class="d-flex gap-2"><?php csrf_field(); ?><input type="hidden" name="action" value="kategori"><input name="name" class="form-control form-control-sm" placeholder="Nama kategori" required><button class="btn btn-sm btn-outline-success">Tambah</button></form></div></div>
    </div>
</div>

<div class="modal fade" id="editModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
    <form method="post">
        <?php csrf_field(); ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" id="editId">
        <div class="modal-header"><h5 class="modal-title">Ubah Produk</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><div class="row g-3">
            <div class="col-md-6"><label class="form-label small fw-semibold">Nama</label><input name="name" id="editName" class="form-control form-control-sm" required></div>
            <div class="col-md-6"><label class="form-label small fw-semibold">SKU</label><input name="sku" id="editSku" class="form-control form-control-sm" required></div>
            <div class="col-md-4"><label class="form-label small fw-semibold">Kategori</label><select name="category_id" id="editCat" class="form-select form-select-sm"><?php foreach ($categories as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label small fw-semibold">Ukuran</label><input name="size" id="editSize" class="form-control form-control-sm"></div>
            <div class="col-md-4"><label class="form-label small fw-semibold">Satuan</label><input name="unit" id="editUnit" class="form-control form-control-sm"></div>
            <div class="col-md-3"><label class="form-label small fw-semibold">Harga</label><input type="number" name="price" id="editPrice" class="form-control form-control-sm"></div>
            <div class="col-md-3"><label class="form-label small fw-semibold">HPP</label><input type="number" name="cost" id="editCost" class="form-control form-control-sm"></div>
            <div class="col-md-3"><label class="form-label small fw-semibold">Stok</label><input type="number" name="stock" id="editStock" class="form-control form-control-sm"></div>
            <div class="col-md-3"><label class="form-label small fw-semibold">Min. Stok</label><input type="number" name="min_stock" id="editMin" class="form-control form-control-sm"></div>
        </div><div class="alert alert-light small mt-3 mb-0">Perubahan harga/HPP hanya berlaku untuk transaksi baru; riwayat tidak diubah.</div></div>
        <div class="modal-footer"><button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Batal</button><button class="btn btn-sm text-white fw-bold" style="background:var(--smcorner)">Simpan</button></div>
    </form>
</div></div></div>

<div class="modal fade" id="hapusModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <form method="post">
        <?php csrf_field(); ?><input type="hidden" name="action" value="hapus"><input type="hidden" name="id" id="hapusId">
        <div class="modal-header"><h5 class="modal-title text-danger">Hapus Produk Permanen</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><div class="alert alert-danger small">Ketik <b id="hapusNama"></b> persis untuk mengonfirmasi.</div><input type="text" name="confirm_name" id="hapusConfirm" class="form-control form-control-sm" autocomplete="off"><div class="form-text small" id="hapusInfo"></div></div>
        <div class="modal-footer"><button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Batal</button><button type="submit" class="btn btn-sm btn-danger" id="btnHapus" disabled>Hapus Permanen</button></div>
    </form>
</div></div></div>

<script>
document.addEventListener('DOMContentLoaded', function () {
if (typeof bootstrap === 'undefined') return;
var produkData = <?= json_encode($products, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
var editModal = new bootstrap.Modal(document.getElementById('editModal'));
document.querySelectorAll('.btn-edit').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var p = produkData.find(function (x) { return x.id == btn.dataset.id; });
        if (!p) return;
        document.getElementById('editId').value = p.id;
        document.getElementById('editName').value = p.name;
        document.getElementById('editSku').value = p.sku;
        document.getElementById('editCat').value = p.category_id;
        document.getElementById('editSize').value = p.size;
        document.getElementById('editUnit').value = p.unit;
        document.getElementById('editPrice').value = p.price;
        document.getElementById('editCost').value = p.cost;
        document.getElementById('editStock').value = p.stock;
        document.getElementById('editMin').value = p.min_stock;
        editModal.show();
    });
});
var hapusModal = new bootstrap.Modal(document.getElementById('hapusModal'));
var expectedName = '';
document.querySelectorAll('.btn-hapus').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var used = parseInt(btn.dataset.used, 10) || 0;
        if (used > 0) { alert('Produk "' + btn.dataset.name + '" sudah dipakai ' + used + ' transaksi dan tidak bisa dihapus.'); return; }
        expectedName = btn.dataset.name;
        document.getElementById('hapusId').value = btn.dataset.id;
        document.getElementById('hapusNama').textContent = expectedName;
        document.getElementById('hapusConfirm').value = '';
        document.getElementById('btnHapus').disabled = true;
        document.getElementById('hapusInfo').textContent = '';
        hapusModal.show();
    });
});
document.getElementById('hapusConfirm').addEventListener('input', function () {
    var ok = this.value === expectedName;
    document.getElementById('btnHapus').disabled = !ok;
    document.getElementById('hapusInfo').textContent = ok ? 'Nama cocok. Tombol hapus aktif.' : '';
});
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
