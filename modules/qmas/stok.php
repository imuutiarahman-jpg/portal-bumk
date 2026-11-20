<?php
require_once __DIR__ . '/../../config/app.php';
require_login();
require_unit('qmas');

$title = 'Stok & Galon – Qmas';
$activeUnit = 'qmas';
$errors = [];

/** Validasi field produk yang dipakai tambah & ubah. */
function stok_product_fields(array $post): array
{
    $f = [
        'name'      => clean($post['name'] ?? ''),
        'sku'       => strtoupper(clean($post['sku'] ?? '')),
        'kind'      => $post['kind'] ?? 'botol',
        'size'      => clean($post['size'] ?? '-'),
        'price'     => max(0, (float) ($post['price'] ?? 0)),
        'cost'      => max(0, (float) ($post['cost'] ?? 0)),
        'stock'     => max(0, (int) ($post['stock'] ?? 0)),
        'min_stock' => max(0, (int) ($post['min_stock'] ?? 0)),
        'unit'      => clean($post['unit'] ?? 'pcs'),
    ];
    if (!in_array($f['kind'], ['galon', 'gelas', 'botol', 'dus'], true)) $f['kind'] = 'botol';
    return $f;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'tambah') {
        $f = stok_product_fields($_POST);
        $errs = [];
        if ($f['name'] === '' || $f['sku'] === '') $errs[] = 'Nama dan SKU wajib diisi.';
        if (!$errs) {
            try {
                run('INSERT INTO qmas_products (name,sku,kind,size,price,cost,stock,min_stock,unit,is_active) VALUES (?,?,?,?,?,?,?,?,?,1)',
                    [$f['name'], $f['sku'], $f['kind'], $f['size'], $f['price'], $f['cost'], $f['stock'], $f['min_stock'], $f['unit']]);
                activity('qmas', 'Tambah produk ' . $f['name']);
                flash_set('success', 'Produk ' . $f['name'] . ' ditambahkan.');
            } catch (Throwable $e) { $errs[] = 'SKU sudah digunakan.'; }
        }
        if ($errs) { foreach ($errs as $err) flash_set('danger', $err); }
        redirect('/modules/qmas/stok.php');
    }

    if ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $f = stok_product_fields($_POST);
        $errs = [];
        if ($id < 1) $errs[] = 'Produk tidak ditemukan.';
        if ($f['name'] === '' || $f['sku'] === '') $errs[] = 'Nama dan SKU wajib diisi.';
        if (!$errs) {
            $dup = val('SELECT COUNT(*) FROM qmas_products WHERE sku=? AND id<>?', [$f['sku'], $id]);
            if ($dup > 0) $errs[] = 'SKU sudah digunakan produk lain.';
        }
        if (!$errs) {
            try {
                run('UPDATE qmas_products SET name=?,sku=?,kind=?,size=?,price=?,cost=?,stock=?,min_stock=?,unit=? WHERE id=?',
                    [$f['name'], $f['sku'], $f['kind'], $f['size'], $f['price'], $f['cost'], $f['stock'], $f['min_stock'], $f['unit'], $id]);
                activity('qmas', 'Ubah produk #' . $id . ' (' . $f['name'] . ')');
                flash_set('success', 'Produk ' . $f['name'] . ' diperbarui.');
            } catch (Throwable $e) { $errs[] = 'Gagal menyimpan: ' . $e->getMessage(); }
        }
        if ($errs) { foreach ($errs as $err) flash_set('danger', $err); }
        redirect('/modules/qmas/stok.php');
    }

    if ($action === 'hapus') {
        $id = (int) ($_POST['id'] ?? 0);
        $typed = clean($_POST['confirm_name'] ?? '');
        $p = row('SELECT * FROM qmas_products WHERE id=?', [$id]);
        if (!$p) {
            flash_set('danger', 'Produk tidak ditemukan.');
        } elseif ($typed !== $p['name']) {
            flash_set('danger', 'Nama yang diketik tidak sesuai. Hapus dibatalkan.');
        } else {
            $used = (int) val('SELECT COUNT(*) FROM qmas_transaction_items WHERE product_id=?', [$id]);
            if ($used > 0) {
                flash_set('danger', 'Produk sudah dipakai ' . $used . ' transaksi dan tidak bisa dihapus.');
            } else {
                try {
                    run('DELETE FROM qmas_products WHERE id=?', [$id]);
                    activity('qmas', 'Hapus permanen produk #' . $id . ' (' . $p['name'] . ')');
                    flash_set('success', 'Produk ' . $p['name'] . ' dihapus permanen.');
                } catch (Throwable $e) {
                    flash_set('danger', 'Gagal menghapus produk: ' . $e->getMessage());
                }
            }
        }
        redirect('/modules/qmas/stok.php');
    }

    if ($action === 'setstock') {
        run('UPDATE qmas_products SET stock=? WHERE id=?', [max(0, (int) ($_POST['stock'] ?? 0)), (int) $_POST['id']]);
        flash_set('success', 'Stok diperbarui.');
        redirect('/modules/qmas/stok.php');
    }

    if ($action === 'tukar') {
        $customer = clean($_POST['customer_name'] ?? '');
        if ($customer === '') $errors[] = 'Nama pelanggan wajib diisi.';
        if (!$errors) {
            run('INSERT INTO qmas_gallon_exchanges (exchange_date,customer_name,full_out,empty_in,notes,user_id) VALUES (NOW(),?,?,?,?,?)',
                [$customer, (int) ($_POST['full_out'] ?? 0), (int) ($_POST['empty_in'] ?? 0), clean($_POST['notes'] ?? ''), current_user()['id']]);
            flash_set('success', 'Pencatatan tukar galon berhasil.');
        }
        redirect('/modules/qmas/stok.php#galon');
    }
}

$products = all('SELECT * FROM qmas_products WHERE is_active = 1 ORDER BY kind, name');
$allProducts = $products;
$exchanges = all('SELECT g.*, u.full_name FROM qmas_gallon_exchanges g LEFT JOIN users u ON u.id=g.user_id ORDER BY exchange_date DESC LIMIT 20');
$totalStock = val('SELECT COALESCE(SUM(stock),0) FROM qmas_products WHERE is_active = 1');
$lowProducts = all('SELECT name, stock, min_stock FROM qmas_products WHERE is_active=1 ORDER BY (stock - min_stock) ASC LIMIT 5');
$lowLabels = []; $lowData = []; $lowColors = [];
foreach ($lowProducts as $lp) {
    $lowLabels[] = $lp['name'];
    $lowData[] = (int) $lp['stock'];
    $lowColors[] = $lp['stock'] <= $lp['min_stock'] ? '#EF4444' : '#F59E0B';
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div><h1 class="section-title mb-1"><i class="bi bi-box-seam" style="color:var(--qmas)"></i> Manajemen Stok & Galon</h1><div class="text-muted small">Sisa stok gudang: <b><?= angka($totalStock) ?> unit</b></div></div>
</div>

<?php if ($errors): ?><div class="alert alert-danger"><?= e(implode('<br>', $errors)) ?></div><?php endif; ?>

<div class="card content-card mb-3"><div class="card-body">
    <div class="chart-title"><i class="bi bi-speedometer2"></i> Stok 5 Produk Terendah</div>
    <div class="chart-box sm"><?= chart('bar', $lowLabels, [['label' => 'Stok tersisa', 'data' => $lowData, 'color' => '#0284C7', 'colors' => $lowColors]]) ?></div>
</div></div>

<ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#stok">Stok Kemasan</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#galon">Tukar Galon Kosong</button></li>
</ul>

<div class="tab-content">
<div class="tab-pane fade show active" id="stok">
    <div class="row g-3">
        <div class="col-lg-9">
            <div class="card content-card">
                <div class="card-header">Daftar Produk</div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0 align-middle">
                        <thead class="table-light"><tr>
                            <th>Produk</th><th>SKU</th><th>Jenis</th><th>Ukuran</th>
                            <th class="text-end">Harga</th><th class="text-end">HPP</th>
                            <th class="text-end">Stok</th><th class="text-end">Min.</th><th>Aksi</th>
                        </tr></thead>
                        <tbody>
                        <?php if (!$products): ?><tr><td colspan="9" class="empty-state">Tidak ada produk.</td></tr><?php endif; ?>
                        <?php foreach ($products as $p): ?>
                            <tr class="<?= !$p['is_active'] ? 'opacity-50' : '' ?>">
                                <td class="small fw-semibold"><?= e($p['name']) ?></td>
                                <td class="small"><?= e($p['sku']) ?></td>
                                <td><span class="badge bg-light text-dark"><?= e(ucfirst($p['kind'])) ?></span></td>
                                <td class="small"><?= e($p['size']) ?></td>
                                <td class="text-end small"><?= rupiah($p['price']) ?></td>
                                <td class="text-end small"><?= rupiah($p['cost']) ?></td>
                                <td class="text-end"><span class="badge bg-<?= $p['stock'] <= $p['min_stock'] ? 'danger' : 'success' ?>"><?= angka($p['stock']) ?></span></td>
                                <td class="text-end small"><?= angka($p['min_stock']) ?></td>
                                <td class="text-nowrap">
                                    <?php if ($p['is_active']): ?>
                                    <form method="post" class="d-inline-flex gap-1 me-1"><?php csrf_field(); ?><input type="hidden" name="action" value="setstock"><input type="hidden" name="id" value="<?= $p['id'] ?>">
                                        <input type="number" name="stock" value="<?= $p['stock'] ?>" class="form-control form-control-sm" style="width:72px" min="0" title="Set stok cepat">
                                        <button class="btn btn-sm btn-outline-primary" title="Set stok">Set</button>
                                    </form>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-sm btn-outline-warning btn-edit" data-id="<?= $p['id'] ?>" title="Ubah produk"><i class="bi bi-pencil"></i> Ubah</button>
                                    <?php $used = (int) val('SELECT COUNT(*) FROM qmas_transaction_items WHERE product_id=?', [$p['id']]); ?>
                                    <button type="button" class="btn btn-sm btn-outline-danger btn-hapus" data-id="<?= $p['id'] ?>" data-name="<?= e($p['name']) ?>" data-used="<?= $used ?>" title="<?= $used > 0 ? 'Sudah dipakai '.$used.' transaksi — tidak bisa dihapus' : 'Hapus permanen (belum dipakai transaksi)' ?>"><i class="bi bi-trash"></i> Hapus</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-3">
            <div class="card content-card">
                <div class="card-header">Tambah Produk / Kemasan</div>
                <div class="card-body">
                    <form method="post"><?php csrf_field(); ?><input type="hidden" name="action" value="tambah">
                        <div class="mb-2"><label class="form-label small fw-semibold">Nama Produk</label><input name="name" class="form-control form-control-sm" required></div>
                        <div class="mb-2"><label class="form-label small fw-semibold">SKU</label><input name="sku" class="form-control form-control-sm" required></div>
                        <div class="row g-2 mb-2">
                            <div class="col-6"><label class="form-label small fw-semibold">Jenis</label><select name="kind" class="form-select form-select-sm"><option value="galon">Galon</option><option value="gelas">Gelas</option><option value="botol" selected>Botol</option><option value="dus">Dus</option></select></div>
                            <div class="col-6"><label class="form-label small fw-semibold">Ukuran</label><input name="size" class="form-control form-control-sm" placeholder="600ml"></div>
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-6"><label class="form-label small fw-semibold">Harga</label><input type="number" name="price" class="form-control form-control-sm" min="0" value="0"></div>
                            <div class="col-6"><label class="form-label small fw-semibold">HPP</label><input type="number" name="cost" class="form-control form-control-sm" min="0" value="0"></div>
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-6"><label class="form-label small fw-semibold">Satuan</label><input name="unit" class="form-control form-control-sm" value="pcs"></div>
                            <div class="col-6"><label class="form-label small fw-semibold">Stok Awal</label><input type="number" name="stock" class="form-control form-control-sm" value="0"></div>
                        </div>
                        <div class="mb-3"><label class="form-label small fw-semibold">Min. Stok</label><input type="number" name="min_stock" class="form-control form-control-sm" value="0"></div>
                        <button class="btn btn-sm w-100 text-white fw-bold" style="background:var(--qmas)">Simpan Produk</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="tab-pane fade" id="galon">
    <div class="row g-3">
        <div class="col-lg-4">
            <div class="card content-card">
                <div class="card-header">Catat Tukar Galon</div>
                <div class="card-body">
                    <form method="post"><?php csrf_field(); ?><input type="hidden" name="action" value="tukar">
                        <div class="mb-2"><label class="form-label small fw-semibold">Pelanggan / Unit</label><input name="customer_name" class="form-control form-control-sm" required></div>
                        <div class="row g-2 mb-2"><div class="col-6"><label class="form-label small fw-semibold">Galon Penuh Keluar</label><input type="number" name="full_out" class="form-control form-control-sm" value="0" min="0"></div><div class="col-6"><label class="form-label small fw-semibold">Galon Kosong Masuk</label><input type="number" name="empty_in" class="form-control form-control-sm" value="0" min="0"></div></div>
                        <div class="mb-3"><label class="form-label small fw-semibold">Catatan</label><input name="notes" class="form-control form-control-sm"></div>
                        <button class="btn btn-sm w-100 text-white fw-bold" style="background:var(--qmas)">Simpan</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="card content-card">
                <div class="card-header">Riwayat Sirkulasi Galon</div>
                <div class="table-responsive"><table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>Waktu</th><th>Pelanggan</th><th class="text-end">Penuh Keluar</th><th class="text-end">Kosong Masuk</th><th>Catatan</th></tr></thead>
                    <tbody><?php if (!$exchanges): ?><tr><td colspan="5" class="empty-state">Belum ada data.</td></tr><?php endif; ?>
                    <?php foreach ($exchanges as $g): ?><tr>
                        <td class="small"><?= tgl($g['exchange_date'], true) ?></td><td class="small"><?= e($g['customer_name']) ?></td>
                        <td class="text-end"><?= angka($g['full_out']) ?></td><td class="text-end"><?= angka($g['empty_in']) ?></td>
                        <td class="small text-muted"><?= e($g['notes']) ?></td>
                    </tr><?php endforeach; ?></tbody>
                </table></div>
            </div>
        </div>
    </div>
</div>
</div>

<!-- Modal Ubah Produk -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <form method="post" id="editForm">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" id="editId">
            <div class="modal-header"><h5 class="modal-title">Ubah Produk</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label small fw-semibold">Nama Produk</label><input name="name" id="editName" class="form-control form-control-sm" required></div>
                    <div class="col-md-6"><label class="form-label small fw-semibold">SKU</label><input name="sku" id="editSku" class="form-control form-control-sm" required></div>
                    <div class="col-md-4"><label class="form-label small fw-semibold">Jenis</label><select name="kind" id="editKind" class="form-select form-select-sm"><option value="galon">Galon</option><option value="gelas">Gelas</option><option value="botol">Botol</option><option value="dus">Dus</option></select></div>
                    <div class="col-md-4"><label class="form-label small fw-semibold">Ukuran</label><input name="size" id="editSize" class="form-control form-control-sm"></div>
                    <div class="col-md-4"><label class="form-label small fw-semibold">Satuan</label><input name="unit" id="editUnit" class="form-control form-control-sm"></div>
                    <div class="col-md-3"><label class="form-label small fw-semibold">Harga Jual</label><input type="number" name="price" id="editPrice" class="form-control form-control-sm" min="0"></div>
                    <div class="col-md-3"><label class="form-label small fw-semibold">HPP</label><input type="number" name="cost" id="editCost" class="form-control form-control-sm" min="0"></div>
                    <div class="col-md-3"><label class="form-label small fw-semibold">Stok</label><input type="number" name="stock" id="editStock" class="form-control form-control-sm" min="0"></div>
                    <div class="col-md-3"><label class="form-label small fw-semibold">Min. Stok</label><input type="number" name="min_stock" id="editMin" class="form-control form-control-sm" min="0"></div>
                </div>
                <div class="alert alert-light small mt-3 mb-0">Perubahan harga/HPP hanya berlaku untuk transaksi baru. Riwayat transaksi lama tidak diubah.</div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Batal</button><button class="btn btn-sm text-white fw-bold" style="background:var(--qmas)">Simpan Perubahan</button></div>
        </form>
    </div></div>
</div>

<!-- Modal Hapus Permanen -->
<div class="modal fade" id="hapusModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <form method="post" id="hapusForm">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="hapus">
            <input type="hidden" name="id" id="hapusId">
            <div class="modal-header"><h5 class="modal-title text-danger"><i class="bi bi-trash"></i> Hapus Produk Permanen</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="alert alert-danger small">Produk yang dihapus tidak bisa dikembalikan. Ketik <b id="hapusNama"></b> persis untuk mengonfirmasi.</div>
                <input type="text" name="confirm_name" id="hapusConfirm" class="form-control form-control-sm" placeholder="Ketik nama produk di sini" autocomplete="off">
                <div class="form-text small" id="hapusInfo"></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Batal</button><button type="submit" class="btn btn-sm btn-danger" id="btnHapus" disabled>Hapus Permanen</button></div>
        </form>
    </div></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
if (typeof bootstrap === 'undefined') { console.warn('Bootstrap belum termuat — tombol modal tidak aktif.'); return; }
var produkData = <?= json_encode($allProducts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
var editModal = new bootstrap.Modal(document.getElementById('editModal'));
document.querySelectorAll('.btn-edit').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var p = produkData.find(function (x) { return x.id == btn.dataset.id; });
        if (!p) return;
        document.getElementById('editId').value = p.id;
        document.getElementById('editName').value = p.name;
        document.getElementById('editSku').value = p.sku;
        document.getElementById('editKind').value = p.kind;
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
        if (used > 0) {
            alert('Produk "' + btn.dataset.name + '" sudah dipakai ' + used + ' transaksi dan tidak bisa dihapus.');
            return;
        }
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
    document.getElementById('hapusInfo').textContent = ok ? 'Nama cocok. Tombol hapus sudah aktif.' : '';
});
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
