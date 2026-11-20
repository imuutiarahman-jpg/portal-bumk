<?php
require_once __DIR__ . '/../../config/app.php';
require_login();
require_unit('daqam');

$title = 'Menu & Resep – Daqam';
$activeUnit = 'daqam';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'kategori') {
        $name = clean($_POST['name'] ?? '');
        if ($name !== '') { run('INSERT INTO daqam_categories (name) VALUES (?)', [$name]); flash_set('success','Kategori ditambahkan.'); }
        redirect('/modules/daqam/menu.php');
    }
    if ($action === 'tambah') {
        $name = clean($_POST['name'] ?? ''); $sku = strtoupper(clean($_POST['sku'] ?? ''));
        if ($name === '' || $sku === '') $errors[] = 'Nama dan SKU wajib diisi.';
        if (!$errors) {
            try {
                run('INSERT INTO daqam_menu (category_id,name,sku,price,status,icon) VALUES (?,?,?,?,?,?)',
                    [(int)$_POST['category_id'],$name,$sku,(float)($_POST['price'] ?? 0),$_POST['status'] ?? 'tersedia',clean($_POST['icon'] ?? '🍽️')]);
                flash_set('success','Menu ditambahkan.');
            } catch (Throwable $e) { $errors[] = 'SKU sudah digunakan.'; }
        }
        redirect('/modules/daqam/menu.php');
    }
    if ($action === 'status') {
        $status = $_POST['status'] === 'tersedia' ? 'tersedia' : 'habis';
        run('UPDATE daqam_menu SET status=? WHERE id=?', [$status,(int)$_POST['id']]);
        flash_set('success','Status menu diperbarui.');
        redirect('/modules/daqam/menu.php');
    }
    if ($action === 'resep') {
        $mid=(int)($_POST['menu_id']??0); $gid=(int)($_POST['ingredient_id']??0); $qty=(float)($_POST['quantity']??0);
        if ($mid && $gid && $qty>0) {
            run('INSERT INTO daqam_recipes (menu_id,ingredient_id,quantity) VALUES (?,?,?) ON DUPLICATE KEY UPDATE quantity=VALUES(quantity)',[$mid,$gid,$qty]);
            flash_set('success','Resep disimpan.');
        }
        redirect('/modules/daqam/menu.php#resep');
    }
}

$menu = all('SELECT m.*, c.name category_name FROM daqam_menu m JOIN daqam_categories c ON c.id=m.category_id ORDER BY c.id,m.id');
$cats = all('SELECT * FROM daqam_categories ORDER BY id');
$bahan = all('SELECT * FROM daqam_ingredients ORDER BY name');
$resep = all('SELECT r.*, m.name menu_name, g.name bahan_name, g.unit FROM daqam_recipes r JOIN daqam_menu m ON m.id=r.menu_id JOIN daqam_ingredients g ON g.id=r.ingredient_id ORDER BY m.name, g.name');

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div><h1 class="section-title mb-1"><i class="bi bi-journal-bookmark" style="color:var(--daqam)"></i> Manajemen Menu & Resep</h1><div class="text-muted small">Harga, status ketersediaan, dan resep bahan baku</div></div>
</div>

<?php if ($errors): ?><div class="alert alert-danger"><?= e(implode('<br>',$errors)) ?></div><?php endif; ?>

<ul class="nav nav-tabs mb-3"><li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#menu">Daftar Menu</button></li><li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#resep">Resep</button></li></ul>
<div class="tab-content">
<div class="tab-pane fade show active" id="menu">
<div class="row g-3">
    <div class="col-lg-8"><div class="card content-card">
        <div class="card-header">Daftar Menu</div>
        <div class="table-responsive"><table class="table table-sm table-hover mb-0 align-middle">
            <thead class="table-light"><tr><th>Menu</th><th>Kategori</th><th>SKU</th><th class="text-end">Harga</th><th>Status</th><th>Ubah</th></tr></thead>
            <tbody><?php foreach ($menu as $m): ?><tr>
                <td class="small fw-semibold"><?= e($m['icon'].' '.$m['name']) ?></td><td class="small"><?= e($m['category_name']) ?></td><td class="small"><?= e($m['sku']) ?></td>
                <td class="text-end small"><?= rupiah($m['price']) ?></td>
                <td><span class="badge bg-<?= $m['status']==='tersedia'?'success':'danger' ?>"><?= e(ucfirst($m['status'])) ?></span></td>
                <td><form method="post" class="d-flex gap-1"><?php csrf_field(); ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= $m['id'] ?>"><select name="status" class="form-select form-select-sm" style="width:100px"><option value="tersedia" <?= $m['status']==='tersedia'?'selected':'' ?>>Tersedia</option><option value="habis" <?= $m['status']==='habis'?'selected':'' ?>>Habis</option></select><button class="btn btn-sm btn-outline-primary">Set</button></form></td>
            </tr><?php endforeach; ?></tbody>
        </table></div>
    </div></div>
    <div class="col-lg-4">
        <div class="card content-card mb-3"><div class="card-header">Tambah Menu</div><div class="card-body">
            <form method="post"><?php csrf_field(); ?><input type="hidden" name="action" value="tambah">
                <div class="mb-2"><label class="form-label small fw-semibold">Nama Menu</label><input name="name" class="form-control form-control-sm" required></div>
                <div class="row g-2 mb-2"><div class="col-6"><label class="form-label small fw-semibold">SKU</label><input name="sku" class="form-control form-control-sm" required></div><div class="col-6"><label class="form-label small fw-semibold">Ikon</label><input name="icon" class="form-control form-control-sm" value="🍽️"></div></div>
                <div class="row g-2 mb-2"><div class="col-6"><label class="form-label small fw-semibold">Kategori</label><select name="category_id" class="form-select form-select-sm"><?php foreach ($cats as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div><div class="col-6"><label class="form-label small fw-semibold">Harga</label><input type="number" name="price" class="form-control form-control-sm" value="0"></div></div>
                <div class="mb-3"><label class="form-label small fw-semibold">Status</label><select name="status" class="form-select form-select-sm"><option value="tersedia">Tersedia</option><option value="habis">Habis</option></select></div>
                <button class="btn btn-sm w-100 text-white fw-bold" style="background:var(--daqam)">Simpan Menu</button>
            </form>
        </div></div>
        <div class="card content-card"><div class="card-header">Tambah Kategori</div><div class="card-body"><form method="post" class="d-flex gap-2"><?php csrf_field(); ?><input type="hidden" name="action" value="kategori"><input name="name" class="form-control form-control-sm" placeholder="Nama kategori" required><button class="btn btn-sm btn-outline-warning">Tambah</button></form></div></div>
    </div>
</div>
</div>

<div class="tab-pane fade" id="resep">
<div class="row g-3">
    <div class="col-lg-5"><div class="card content-card"><div class="card-header">Tambah / Ubah Resep</div><div class="card-body">
        <form method="post"><?php csrf_field(); ?><input type="hidden" name="action" value="resep">
            <div class="mb-2"><label class="form-label small fw-semibold">Menu</label><select name="menu_id" class="form-select form-select-sm"><?php foreach ($menu as $m): ?><option value="<?= $m['id'] ?>"><?= e($m['name']) ?></option><?php endforeach; ?></select></div>
            <div class="mb-2"><label class="form-label small fw-semibold">Bahan Baku</label><select name="ingredient_id" class="form-select form-select-sm"><?php foreach ($bahan as $b): ?><option value="<?= $b['id'] ?>"><?= e($b['name'].' ('.$b['unit'].')') ?></option><?php endforeach; ?></select></div>
            <div class="mb-3"><label class="form-label small fw-semibold">Jumlah per Porsi</label><input type="number" step="0.01" name="quantity" class="form-control form-control-sm" value="1" required></div>
            <button class="btn btn-sm w-100 text-white fw-bold" style="background:var(--daqam)">Simpan Resep</button>
        </form>
    </div></div></div>
    <div class="col-lg-7"><div class="card content-card"><div class="card-header">Daftar Resep</div><div class="table-responsive"><table class="table table-sm mb-0">
        <thead class="table-light"><tr><th>Menu</th><th>Bahan</th><th class="text-end">Jumlah / Porsi</th></tr></thead>
        <tbody><?php if (!$resep): ?><tr><td colspan="3" class="empty-state">Belum ada resep.</td></tr><?php endif; ?>
        <?php foreach ($resep as $r): ?><tr><td class="small"><?= e($r['menu_name']) ?></td><td class="small"><?= e($r['bahan_name']) ?></td><td class="text-end small"><?= angka($r['quantity']).' '.e($r['unit']) ?></td></tr><?php endforeach; ?></tbody>
    </table></div></div></div>
</div>
</div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
