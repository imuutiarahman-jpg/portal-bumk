<?php
require_once __DIR__ . '/../../config/app.php';
require_login();
require_unit('daqam');

$title = 'Bahan Baku – Daqam';
$activeUnit = 'daqam';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    if ($action === 'tambah') {
        $name = clean($_POST['name'] ?? '');
        if ($name === '') $errors[] = 'Nama bahan wajib diisi.';
        if (!$errors) {
            run('INSERT INTO daqam_ingredients (name,unit,stock,min_stock,last_price) VALUES (?,?,?,?,?)',
                [$name,clean($_POST['unit'] ?? 'pcs'),(float)($_POST['stock'] ?? 0),(float)($_POST['min_stock'] ?? 0),(float)($_POST['last_price'] ?? 0)]);
            flash_set('success','Bahan baku ditambahkan.');
        }
        redirect('/modules/daqam/bahanbaku.php');
    }
    if ($action === 'belanja') {
        $id=(int)($_POST['id']??0); $qty=(float)($_POST['qty']??0); $price=(float)($_POST['price']??0);
        if ($id<1 || $qty<=0) $errors[] = 'Jumlah belanja harus lebih dari 0.';
        if (!$errors) {
            run('UPDATE daqam_ingredients SET stock = stock + ?, last_price = ? WHERE id=?',[$qty,$price,$id]);
            activity('daqam','Belanja bahan baku #'.$id.' sebanyak '.$qty);
            flash_set('success','Stok bahan baku ditambahkan.');
        }
        redirect('/modules/daqam/bahanbaku.php');
    }
}

$bahan = all('SELECT * FROM daqam_ingredients ORDER BY name');
$nilai = 0; foreach ($bahan as $b) $nilai += (float)$b['stock']*(float)$b['last_price'];

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div><h1 class="section-title mb-1"><i class="bi bi-basket" style="color:var(--daqam)"></i> Manajemen Bahan Baku</h1><div class="text-muted small">Estimasi nilai stok: <b><?= rupiah($nilai) ?></b> · pencatatan belanja harian</div></div>
</div>

<?php if ($errors): ?><div class="alert alert-danger"><?= e(implode('<br>',$errors)) ?></div><?php endif; ?>

<div class="row g-3">
    <div class="col-lg-8"><div class="card content-card">
        <div class="card-header">Daftar Bahan Baku</div>
        <div class="table-responsive"><table class="table table-sm table-hover mb-0 align-middle">
            <thead class="table-light"><tr><th>Bahan</th><th class="text-end">Stok</th><th class="text-end">Min.</th><th class="text-end">Harga Terakhir</th><th>Belanja Bahan</th></tr></thead>
            <tbody><?php foreach ($bahan as $b): ?><tr>
                <td class="small fw-semibold"><?= e($b['name']) ?></td>
                <td class="text-end"><span class="badge bg-<?= $b['stock']<=$b['min_stock']?'danger':'success' ?>"><?= angka($b['stock']).' '.e($b['unit']) ?></span></td>
                <td class="text-end small"><?= angka($b['min_stock']) ?></td><td class="text-end small"><?= rupiah($b['last_price']) ?></td>
                <td><form method="post" class="d-flex gap-1"><?php csrf_field(); ?><input type="hidden" name="action" value="belanja"><input type="hidden" name="id" value="<?= $b['id'] ?>"><input type="number" step="0.01" name="qty" placeholder="qty" class="form-control form-control-sm" style="width:70px" required><input type="number" name="price" placeholder="harga" class="form-control form-control-sm" style="width:90px" value="<?= $b['last_price'] ?>"><button class="btn btn-sm btn-outline-warning">Tambah</button></form></td>
            </tr><?php endforeach; ?></tbody>
        </table></div>
    </div></div>
    <div class="col-lg-4"><div class="card content-card"><div class="card-header">Tambah Bahan Baku</div><div class="card-body">
        <form method="post"><?php csrf_field(); ?><input type="hidden" name="action" value="tambah">
            <div class="mb-2"><label class="form-label small fw-semibold">Nama Bahan</label><input name="name" class="form-control form-control-sm" required></div>
            <div class="row g-2 mb-2"><div class="col-6"><label class="form-label small fw-semibold">Satuan</label><input name="unit" class="form-control form-control-sm" placeholder="gram / kg / pcs"></div><div class="col-6"><label class="form-label small fw-semibold">Stok Awal</label><input type="number" step="0.01" name="stock" class="form-control form-control-sm" value="0"></div></div>
            <div class="row g-2 mb-3"><div class="col-6"><label class="form-label small fw-semibold">Min. Stok</label><input type="number" step="0.01" name="min_stock" class="form-control form-control-sm" value="0"></div><div class="col-6"><label class="form-label small fw-semibold">Harga</label><input type="number" name="last_price" class="form-control form-control-sm" value="0"></div></div>
            <button class="btn btn-sm w-100 text-white fw-bold" style="background:var(--daqam)">Simpan Bahan</button>
        </form>
    </div></div></div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
