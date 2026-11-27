<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../includes/sm_import.php';
require_login();
require_unit('smcorner');
sm_import_ensure_schema();

$title = 'Katalog & Stok – SM Corner';
$activeUnit = 'smcorner';

/** Kembali ke katalog dengan filter yang masih aktif setelah aksi POST. */
function sm_catalog_back(): string
{
    $parts = [];
    foreach (['q', 'category', 'stock', 'page'] as $key) {
        $v = trim((string) ($_POST['filter_' . $key] ?? ''));
        if ($v !== '') $parts[$key] = $v;
    }
    return '/modules/smcorner/produk.php' . ($parts ? '?' . http_build_query($parts) : '');
}

/** Hidden field filter yang disertakan pada setiap form aksi. */
function sm_filter_fields(): void
{
    foreach (['q', 'category', 'stock', 'page'] as $key) {
        $v = trim((string) ($_GET[$key] ?? ''));
        if ($v !== '') echo '<input type="hidden" name="filter_' . $key . '" value="' . e($v) . '">';
    }
}

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

/* ==================================================================
   Aksi POST
   ================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $back = sm_catalog_back();

    if ($action === 'kategori' || $action === 'kategori_update') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = clean($_POST['name'] ?? '');
        if ($name === '') {
            flash_set('danger', 'Nama kategori wajib diisi.');
        } else {
            $dupe = $id > 0
                ? val('SELECT COUNT(*) FROM sm_categories WHERE name=? AND id<>?', [$name, $id])
                : val('SELECT COUNT(*) FROM sm_categories WHERE name=?', [$name]);
            if ($dupe > 0) {
                flash_set('danger', 'Nama kategori sudah digunakan.');
            } elseif ($id > 0) {
                run('UPDATE sm_categories SET name=?, description=? WHERE id=?', [$name, clean($_POST['description'] ?? ''), $id]);
                flash_set('success', 'Kategori diperbarui.');
            } else {
                run('INSERT INTO sm_categories (name,description) VALUES (?,?)', [$name, clean($_POST['description'] ?? '')]);
                flash_set('success', 'Kategori ditambahkan.');
            }
        }
        redirect($back);
    }

    if ($action === 'kategori_hapus') {
        $id = (int) ($_POST['id'] ?? 0);
        $used = (int) val('SELECT COUNT(*) FROM sm_products WHERE category_id=?', [$id]);
        if ($id < 1) {
            flash_set('danger', 'Kategori tidak ditemukan.');
        } elseif ($used > 0) {
            flash_set('danger', 'Kategori masih dipakai ' . $used . ' produk dan tidak bisa dihapus.');
        } else {
            run('DELETE FROM sm_categories WHERE id=?', [$id]);
            flash_set('success', 'Kategori dihapus.');
        }
        redirect($back);
    }

    if ($action === 'tambah') {
        $f = sm_product_fields($_POST);
        $errs = [];
        if ($f['name'] === '' || $f['sku'] === '' || $f['category'] < 1) $errs[] = 'Nama, SKU, dan kategori wajib diisi.';
        if (!$errs && val('SELECT COUNT(*) FROM sm_products WHERE sku=?', [$f['sku']]) > 0) $errs[] = 'SKU sudah digunakan.';
        if (!$errs) {
            run('INSERT INTO sm_products (category_id,name,sku,size,price,cost,stock,min_stock,unit) VALUES (?,?,?,?,?,?,?,?,?)',
                [$f['category'], $f['name'], $f['sku'], $f['size'], $f['price'], $f['cost'], $f['stock'], $f['min_stock'], $f['unit']]);
            activity('smcorner', 'Tambah produk ' . $f['name']);
            flash_set('success', 'Produk ' . $f['name'] . ' ditambahkan.');
        }
        foreach ($errs as $err) flash_set('danger', $err);
        redirect($back);
    }

    if ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $f = sm_product_fields($_POST);
        $errs = [];
        if ($id < 1) $errs[] = 'Produk tidak ditemukan.';
        if ($f['name'] === '' || $f['sku'] === '' || $f['category'] < 1) $errs[] = 'Nama, SKU, dan kategori wajib diisi.';
        if (!$errs && val('SELECT COUNT(*) FROM sm_products WHERE sku=? AND id<>?', [$f['sku'], $id]) > 0) $errs[] = 'SKU sudah digunakan produk lain.';
        if (!$errs) {
            run('UPDATE sm_products SET category_id=?,name=?,sku=?,size=?,price=?,cost=?,stock=?,min_stock=?,unit=? WHERE id=?',
                [$f['category'], $f['name'], $f['sku'], $f['size'], $f['price'], $f['cost'], $f['stock'], $f['min_stock'], $f['unit'], $id]);
            activity('smcorner', 'Ubah produk #' . $id . ' (' . $f['name'] . ')');
            flash_set('success', 'Produk ' . $f['name'] . ' diperbarui.');
        }
        foreach ($errs as $err) flash_set('danger', $err);
        redirect($back);
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
                run('DELETE FROM sm_products WHERE id=?', [$id]);
                activity('smcorner', 'Hapus permanen produk #' . $id . ' (' . $p['name'] . ')');
                flash_set('success', 'Produk ' . $p['name'] . ' dihapus permanen.');
            }
        }
        redirect($back);
    }

    if ($action === 'setstock') {
        sm_import_set_product_stock((int) $_POST['id'], (int) ($_POST['stock'] ?? 0));
        flash_set('success', 'Stok diperbarui (termasuk varian ukuran).');
        redirect($back);
    }

    if ($action === 'variant_cost') {
        $ids = $_POST['variant_id'] ?? [];
        $costs = $_POST['cost'] ?? [];
        if (!is_array($ids)) $ids = [];
        if (!is_array($costs)) $costs = [];
        $saved = 0;
        foreach ($ids as $i => $vid) {
            $vid = (int) $vid;
            if ($vid < 1) continue;
            run('UPDATE sm_product_variants SET cost=? WHERE id=?', [max(0, (float) ($costs[$i] ?? 0)), $vid]);
            $saved++;
        }
        flash_set($saved ? 'success' : 'danger', $saved ? ('HPP ' . $saved . ' varian diperbarui.') : 'Tidak ada varian yang disimpan.');
        redirect($back);
    }

    redirect($back);
}

/* ==================================================================
   Filter, pencarian, dan pagination
   ================================================================== */
$q = trim((string) ($_GET['q'] ?? ''));
$categoryFilter = (int) ($_GET['category'] ?? 0);
$stockFilter = (string) ($_GET['stock'] ?? 'all');
if (!in_array($stockFilter, ['all', 'low', 'empty'], true)) $stockFilter = 'all';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;

$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(p.name LIKE ? OR p.sku LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
if ($categoryFilter > 0) {
    $where[] = 'p.category_id = ?';
    $params[] = $categoryFilter;
}
if ($stockFilter === 'low') {
    $where[] = 'p.stock <= p.min_stock';
} elseif ($stockFilter === 'empty') {
    $where[] = 'p.stock <= 0';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$totalProducts = (int) val('SELECT COUNT(*) FROM sm_products p ' . $whereSql, $params);
$pageCount = max(1, (int) ceil($totalProducts / $perPage));
if ($page > $pageCount) $page = $pageCount;
$offset = ($page - 1) * $perPage;

$pageUrl = static function (int $p) use ($q, $categoryFilter, $stockFilter): string {
    $query = [];
    if ($q !== '') $query['q'] = $q;
    if ($categoryFilter > 0) $query['category'] = $categoryFilter;
    if ($stockFilter !== 'all') $query['stock'] = $stockFilter;
    if ($p > 1) $query['page'] = $p;
    return '/modules/smcorner/produk.php' . ($query ? '?' . http_build_query($query) : '');
};

$products = all('SELECT p.*, c.name category_name,
    (SELECT COUNT(*) FROM sm_product_variants v WHERE v.product_id=p.id) variant_count,
    (SELECT MIN(v.price) FROM sm_product_variants v WHERE v.product_id=p.id) variant_min,
    (SELECT MAX(v.price) FROM sm_product_variants v WHERE v.product_id=p.id) variant_max,
    (SELECT MIN(v.cost) FROM sm_product_variants v WHERE v.product_id=p.id) variant_cost_min,
    (SELECT MAX(v.cost) FROM sm_product_variants v WHERE v.product_id=p.id) variant_cost_max
    FROM sm_products p JOIN sm_categories c ON c.id=p.category_id ' . $whereSql
    . ' ORDER BY c.name, p.name LIMIT ' . $perPage . ' OFFSET ' . $offset, $params);

$categories = all('SELECT c.*, (SELECT COUNT(*) FROM sm_products p WHERE p.category_id=c.id) product_count FROM sm_categories c ORDER BY c.name');
$inv = sm_inventory_values();

/* Produk yang sudah dipakai transaksi/pembelian (hindari query per baris). */
$usedIds = [];
foreach (all('SELECT DISTINCT product_id FROM sm_transaction_items') as $r) $usedIds[(int) $r['product_id']] = true;
foreach (all('SELECT DISTINCT product_id FROM sm_purchase_items') as $r) $usedIds[(int) $r['product_id']] = true;

/* Data varian untuk modal HPP per ukuran. */
$variantData = [];
foreach (all('SELECT id, product_id, size_name, cost FROM sm_product_variants ORDER BY product_id, size_name') as $v) {
    $variantData[(int) $v['product_id']][] = ['id' => (int) $v['id'], 'size' => (string) $v['size_name'], 'cost' => (float) $v['cost']];
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1 class="section-title"><i class="bi bi-boxes" style="color:var(--smcorner)"></i> Katalog & Manajemen Stok</h1>
        <div class="page-sub">Nilai inventaris: <b><?= rupiah($inv['inventory']) ?></b> · Nilai HPP: <?= rupiah($inv['hpp']) ?> · SKU ukuran S–XXXL / meteran</div>
    </div>
    <a href="<?= base_url('/modules/smcorner/pembelian.php') ?>" class="btn btn-sm btn-outline-success"><i class="bi bi-cart-plus"></i> Pembelian</a>
</div>

<div class="card content-card mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small fw-semibold" for="cariProduk">Cari Produk / SKU</label>
                <input type="text" id="cariProduk" name="q" class="form-control form-control-sm" value="<?= e($q) ?>" placeholder="Nama atau kode barang...">
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-semibold" for="filterKategori">Kategori</label>
                <select id="filterKategori" name="category" class="form-select form-select-sm">
                    <option value="0">Semua kategori</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" <?= $categoryFilter === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold" for="filterStok">Stok</label>
                <select id="filterStok" name="stock" class="form-select form-select-sm">
                    <option value="all" <?= $stockFilter === 'all' ? 'selected' : '' ?>>Semua</option>
                    <option value="low" <?= $stockFilter === 'low' ? 'selected' : '' ?>>Menipis</option>
                    <option value="empty" <?= $stockFilter === 'empty' ? 'selected' : '' ?>>Habis</option>
                </select>
            </div>
            <div class="col-md-auto d-flex gap-2">
                <button class="btn btn-sm btn-success"><i class="bi bi-funnel"></i> Terapkan</button>
                <a href="<?= base_url('/modules/smcorner/produk.php') ?>" class="btn btn-sm btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-9">
        <div class="card content-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Daftar Produk</span>
                <span class="badge bg-light text-dark"><?= angka($totalProducts) ?> produk</span>
            </div>
            <div class="table-responsive"><table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light"><tr><th>Produk</th><th>Kategori · Ukuran</th><th>Varian</th><th class="text-end">Harga · HPP</th><th class="text-end">Stok</th><th>Aksi</th></tr></thead>
                <tbody>
                <?php if (!$products): ?><tr><td colspan="6" class="empty-state">Tidak ada produk yang cocok dengan filter.</td></tr><?php endif; ?>
                <?php foreach ($products as $p):
                    $costMin = (float) $p['variant_cost_min'];
                    $costMax = (float) $p['variant_cost_max'];
                ?>
                    <tr>
                        <td class="small">
                            <div class="fw-semibold"><?= e($p['name']) ?></div>
                            <div class="text-muted"><?= e($p['sku']) ?></div>
                        </td>
                        <td class="small">
                            <div><?= e($p['category_name']) ?></div>
                            <div class="text-muted"><?= e($p['size']) ?></div>
                        </td>
                        <td class="small"><?php if ($p['variant_count']): ?><span class="badge bg-light text-dark"><?= (int) $p['variant_count'] ?> ukuran</span><div class="text-muted"><?= rupiah($p['variant_min']) ?>–<?= rupiah($p['variant_max']) ?></div><?php else: ?>—<?php endif; ?></td>
                        <td class="text-end small">
                            <div class="fw-semibold"><?= rupiah($p['price']) ?></div>
                            <?php if ($p['variant_count']): ?>
                                <div class="text-muted" title="HPP per ukuran">HPP <?= $costMin == $costMax ? rupiah($costMin) : rupiah($costMin) . '–' . rupiah($costMax) ?></div>
                            <?php else: ?>
                                <div class="text-muted">HPP <?= rupiah($p['cost']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <span class="badge bg-<?= $p['stock'] <= $p['min_stock'] ? 'danger' : 'success' ?>" title="Min. stok: <?= angka($p['min_stock']) ?> <?= e($p['unit']) ?>"><?= angka($p['stock']) . ' ' . e($p['unit']) ?></span>
                            <div class="small text-muted">min <?= angka($p['min_stock']) ?></div>
                        </td>
                        <td class="text-nowrap">
                            <div class="dropdown">
                                <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Aksi untuk <?= e($p['name']) ?>"><i class="bi bi-three-dots-vertical"></i></button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><button type="button" class="dropdown-item btn-setstock" data-id="<?= (int) $p['id'] ?>" data-name="<?= e($p['name']) ?>" data-stock="<?= (int) $p['stock'] ?>"><i class="bi bi-box-arrow-in-down me-2"></i>Set Stok</button></li>
                                    <li><button type="button" class="dropdown-item btn-edit" data-id="<?= (int) $p['id'] ?>"><i class="bi bi-pencil me-2"></i>Ubah</button></li>
                                    <li><button type="button" class="dropdown-item btn-varian" data-id="<?= (int) $p['id'] ?>" data-name="<?= e($p['name']) ?>"><i class="bi bi-list-check me-2"></i>HPP Varian</button></li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><button type="button" class="dropdown-item text-danger btn-hapus" data-id="<?= (int) $p['id'] ?>" data-name="<?= e($p['name']) ?>" data-used="<?= isset($usedIds[(int) $p['id']]) ? 1 : 0 ?>"><i class="bi bi-trash me-2"></i>Hapus</button></li>
                                </ul>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <div class="card-footer d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span class="small text-muted">
                    Menampilkan <?= $totalProducts ? (($page - 1) * $perPage + 1) : 0 ?>–<?= min($page * $perPage, $totalProducts) ?> dari <?= angka($totalProducts) ?> produk
                </span>
                <nav aria-label="Halaman katalog">
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= base_url($pageUrl($page - 1)) ?>">Sebelumnya</a></li>
                        <li class="page-item disabled"><span class="page-link">Halaman <?= $page ?> / <?= $pageCount ?></span></li>
                        <li class="page-item <?= $page >= $pageCount ? 'disabled' : '' ?>"><a class="page-link" href="<?= base_url($pageUrl($page + 1)) ?>">Berikutnya</a></li>
                    </ul>
                </nav>
            </div>
        </div>
    </div>

    <div class="col-lg-3">
        <div class="card content-card mb-3">
            <div class="card-header">Tambah Produk</div>
            <div class="card-body">
                <form method="post">
                    <?php csrf_field(); ?><input type="hidden" name="action" value="tambah"><?php sm_filter_fields(); ?>
                    <div class="mb-2"><label class="form-label small fw-semibold">Nama Produk</label><input name="name" class="form-control form-control-sm" required></div>
                    <div class="mb-2"><label class="form-label small fw-semibold">SKU</label><input name="sku" class="form-control form-control-sm" required></div>
                    <div class="mb-2"><label class="form-label small fw-semibold">Kategori</label><select name="category_id" class="form-select form-select-sm"><?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
                    <div class="row g-2 mb-2"><div class="col-6"><label class="form-label small fw-semibold">Ukuran</label><input name="size" class="form-control form-control-sm" placeholder="L / meter"></div><div class="col-6"><label class="form-label small fw-semibold">Satuan</label><input name="unit" class="form-control form-control-sm" value="pcs"></div></div>
                    <div class="row g-2 mb-2"><div class="col-6"><label class="form-label small fw-semibold">Harga</label><input type="number" name="price" class="form-control form-control-sm" value="0"></div><div class="col-6"><label class="form-label small fw-semibold">HPP</label><input type="number" name="cost" class="form-control form-control-sm" value="0"></div></div>
                    <div class="row g-2 mb-3"><div class="col-6"><label class="form-label small fw-semibold">Stok</label><input type="number" name="stock" class="form-control form-control-sm" value="0"></div><div class="col-6"><label class="form-label small fw-semibold">Min.</label><input type="number" name="min_stock" class="form-control form-control-sm" value="0"></div></div>
                    <button class="btn btn-sm w-100 text-white fw-bold" style="background:var(--smcorner)">Simpan Produk</button>
                </form>
            </div>
        </div>

        <div class="card content-card">
            <div class="card-header d-flex justify-content-between align-items-center"><span>Kategori</span><button type="button" class="btn btn-sm btn-outline-success btn-kategori-add"><i class="bi bi-plus"></i> Tambah</button></div>
            <div class="card-body p-2">
                <div class="list-group list-group-flush">
                    <?php if (!$categories): ?><div class="list-group-item small text-muted">Belum ada kategori.</div><?php endif; ?>
                    <?php foreach ($categories as $c): ?>
                        <div class="list-group-item d-flex justify-content-between align-items-center gap-2 small px-2">
                            <span class="text-truncate" title="<?= e($c['name']) ?>"><?= e($c['name']) ?></span>
                            <span class="text-nowrap">
                                <span class="badge bg-light text-dark"><?= (int) $c['product_count'] ?></span>
                                <button type="button" class="btn btn-sm btn-outline-warning py-0 px-1 btn-kategori" data-id="<?= (int) $c['id'] ?>" data-name="<?= e($c['name']) ?>" data-description="<?= e($c['description'] ?? '') ?>" title="Ubah kategori"><i class="bi bi-pencil"></i></button>
                                <form method="post" class="d-inline"><?php csrf_field(); ?><input type="hidden" name="action" value="kategori_hapus"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><?php sm_filter_fields(); ?><button class="btn btn-sm btn-outline-danger py-0 px-1" data-confirm="Hapus kategori &quot;<?= e($c['name']) ?>&quot;?" title="Hapus kategori"><i class="bi bi-trash"></i></button></form>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Ubah Produk -->
<div class="modal fade" id="editModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
    <form method="post">
        <?php csrf_field(); ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" id="editId"><?php sm_filter_fields(); ?>
        <div class="modal-header"><h5 class="modal-title">Ubah Produk</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><div class="row g-3">
            <div class="col-md-6"><label class="form-label small fw-semibold">Nama</label><input name="name" id="editName" class="form-control form-control-sm" required></div>
            <div class="col-md-6"><label class="form-label small fw-semibold">SKU</label><input name="sku" id="editSku" class="form-control form-control-sm" required></div>
            <div class="col-md-4"><label class="form-label small fw-semibold">Kategori</label><select name="category_id" id="editCat" class="form-select form-select-sm"><?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
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

<!-- Modal Set Stok -->
<div class="modal fade" id="setStockModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <form method="post">
        <?php csrf_field(); ?><input type="hidden" name="action" value="setstock"><input type="hidden" name="id" id="setStockId"><?php sm_filter_fields(); ?>
        <div class="modal-header"><h5 class="modal-title">Set Stok</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="alert alert-light small mb-3">Produk: <b id="setStockName"></b></div>
            <label class="form-label small fw-semibold">Jumlah stok baru</label>
            <input type="number" name="stock" id="setStockValue" class="form-control form-control-sm" min="0" required>
            <div class="form-text small">Selisih stok akan disebar ke varian ukuran bila produk memiliki varian.</div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Batal</button><button class="btn btn-sm btn-success">Simpan Stok</button></div>
    </form>
</div></div></div>

<!-- Modal HPP Varian -->
<div class="modal fade" id="variantModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
    <form method="post">
        <?php csrf_field(); ?><input type="hidden" name="action" value="variant_cost"><?php sm_filter_fields(); ?>
        <div class="modal-header"><h5 class="modal-title">HPP per Varian — <span id="variantProductName"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="alert alert-light small">Isi HPP per ukuran. Kosongkan / isi 0 untuk memakai HPP produk induk.</div>
            <div id="variantRows"></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Batal</button><button class="btn btn-sm btn-success">Simpan HPP</button></div>
    </form>
</div></div></div>

<!-- Modal Kategori -->
<div class="modal fade" id="kategoriModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <form method="post">
        <?php csrf_field(); ?><input type="hidden" name="action" value="kategori"><input type="hidden" name="id" id="kategoriId"><?php sm_filter_fields(); ?>
        <div class="modal-header"><h5 class="modal-title" id="kategoriTitle">Tambah Kategori</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="mb-2"><label class="form-label small fw-semibold">Nama Kategori</label><input name="name" id="kategoriName" class="form-control form-control-sm" required></div>
            <div><label class="form-label small fw-semibold">Keterangan</label><input name="description" id="kategoriDescription" class="form-control form-control-sm"></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Batal</button><button class="btn btn-sm btn-success">Simpan</button></div>
    </form>
</div></div></div>

<!-- Modal Hapus Produk -->
<div class="modal fade" id="hapusModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <form method="post">
        <?php csrf_field(); ?><input type="hidden" name="action" value="hapus"><input type="hidden" name="id" id="hapusId"><?php sm_filter_fields(); ?>
        <div class="modal-header"><h5 class="modal-title text-danger">Hapus Produk Permanen</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><div class="alert alert-danger small">Ketik <b id="hapusNama"></b> persis untuk mengonfirmasi.</div><input type="text" name="confirm_name" id="hapusConfirm" class="form-control form-control-sm" autocomplete="off"><div class="form-text small" id="hapusInfo"></div></div>
        <div class="modal-footer"><button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Batal</button><button type="submit" class="btn btn-sm btn-danger" id="btnHapus" disabled>Hapus Permanen</button></div>
    </form>
</div></div></div>

<script>
document.addEventListener('DOMContentLoaded', function () {
if (typeof bootstrap === 'undefined') return;
var produkData = <?= json_encode($products, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
var variantData = <?= json_encode($variantData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

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

var setModal = new bootstrap.Modal(document.getElementById('setStockModal'));
document.querySelectorAll('.btn-setstock').forEach(function (btn) {
    btn.addEventListener('click', function () {
        document.getElementById('setStockId').value = btn.dataset.id;
        document.getElementById('setStockName').textContent = btn.dataset.name;
        document.getElementById('setStockValue').value = btn.dataset.stock;
        setModal.show();
    });
});

var variantModal = new bootstrap.Modal(document.getElementById('variantModal'));
document.querySelectorAll('.btn-varian').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var list = variantData[btn.dataset.id] || [];
        var box = document.getElementById('variantRows');
        box.innerHTML = '';
        document.getElementById('variantProductName').textContent = btn.dataset.name;
        if (!list.length) {
            box.innerHTML = '<div class="alert alert-warning small mb-0">Produk ini belum memiliki varian ukuran.</div>';
        } else {
            list.forEach(function (v) {
                var row = document.createElement('div');
                row.className = 'row g-2 align-items-center mb-2';
                row.innerHTML = '<input type="hidden" name="variant_id[]"><div class="col-5 small fw-semibold"></div><div class="col-7"><input type="number" name="cost[]" class="form-control form-control-sm" min="0" step="0.01"></div>';
                row.querySelector('input[name="variant_id[]"]').value = v.id;
                row.querySelector('.col-5').textContent = v.size;
                row.querySelector('input[name="cost[]"]').value = v.cost;
                box.appendChild(row);
            });
        }
        variantModal.show();
    });
});

var kategoriModal = new bootstrap.Modal(document.getElementById('kategoriModal'));
function openKategori(id, name, description) {
    document.getElementById('kategoriId').value = id || '';
    document.getElementById('kategoriName').value = name || '';
    document.getElementById('kategoriDescription').value = description || '';
    document.getElementById('kategoriTitle').textContent = id ? 'Ubah Kategori' : 'Tambah Kategori';
    kategoriModal.show();
}
document.querySelectorAll('.btn-kategori-add').forEach(function (btn) {
    btn.addEventListener('click', function () { openKategori('', '', ''); });
});
document.querySelectorAll('.btn-kategori').forEach(function (btn) {
    btn.addEventListener('click', function () { openKategori(btn.dataset.id, btn.dataset.name, btn.dataset.description); });
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
