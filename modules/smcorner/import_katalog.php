<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../includes/sm_import.php';
require_login();
require_unit('smcorner');

$title = 'Import Katalog Lengkap – SM Corner';
$activeUnit = 'smcorner';
$errors = [];
$preview = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'batal') {
        unset($_SESSION['sm_full_import']);
        flash_set('info', 'Pratinjau dibatalkan.');
        redirect('/modules/smcorner/import_katalog.php');
    }

    if ($action === 'preview') {
        $sheetUrl = trim((string) ($_POST['sheet_url'] ?? ''));
        $tempFile = null;
        $data = null;

        if ($sheetUrl !== '') {
            [$exportUrl, $sheetId, $urlError] = sheet_xlsx_url($sheetUrl);
            if ($urlError) {
                $errors[] = $urlError;
            } else {
                [$path, $fetchError] = sheet_fetch_xlsx($exportUrl);
                if ($fetchError) {
                    $errors[] = $fetchError;
                } else {
                    $tempFile = $path;
                    $sheets = sheet_read_xlsx($path);
                    if (!$sheets) {
                        $errors[] = 'XLSX tidak bisa dibaca. Pastikan sheet bisa diakses publik.';
                    } else {
                        $data = sm_import_parse($sheets, $sheetId);
                        $data['filename'] = 'Google Sheet ' . $sheetId;
                    }
                }
            }
        } else {
            if (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
                $errors[] = 'Isi link Google Sheets atau pilih file CSV terlebih dahulu.';
            } elseif (!preg_match('/\.csv$/i', $_FILES['csv']['name'])) {
                $errors[] = 'File harus berformat .csv.';
            } else {
                $data = sm_import_parse_csv($_FILES['csv']['tmp_name'], $_FILES['csv']['name'], 'csv', $errors);
            }
        }

        if ($tempFile) @unlink($tempFile);
        if ($data && !$errors && ($data['counts']['summary'] || $data['counts']['products'])) {
            $_SESSION['sm_full_import'] = $data;
            $preview = $data;
        } elseif (!$errors) {
            $errors[] = 'Tidak ada data katalog yang bisa dibaca dari sheet.';
        }
    }

    if ($action === 'import') {
        $data = $_SESSION['sm_full_import'] ?? null;
        if (!$data || empty($data['counts'])) {
            $errors[] = 'Tidak ada data pratinjau. Silakan ambil ulang.';
        } else {
            try {
                sm_import_commit($data);
                activity('smcorner', 'Import katalog lengkap: ' . ($data['filename'] ?? 'Google Sheets') . ' (' .
                    $data['counts']['products'] . ' produk, ' . $data['counts']['variants'] . ' varian)');
                unset($_SESSION['sm_full_import']);
                flash_set('success', 'Import berhasil: ' . count($data['categories']) . ' kategori, ' .
                    $data['counts']['products'] . ' produk, ' . $data['counts']['variants'] . ' varian, ' .
                    $data['counts']['summary'] . ' baris ringkasan.');
                redirect('/modules/smcorner/import_katalog.php');
            } catch (Throwable $e) {
                $errors[] = 'Gagal mengimpor: ' . $e->getMessage();
            }
        }
    }
} else {
    $preview = $_SESSION['sm_full_import'] ?? null;
}

$latestBatch = val('SELECT import_batch FROM sm_import_batches ORDER BY id DESC LIMIT 1');
if (!$latestBatch) $latestBatch = val('SELECT import_batch FROM sm_catalog_summary ORDER BY id DESC LIMIT 1');
$saved = $latestBatch ? all('SELECT * FROM sm_catalog_summary WHERE import_batch=? ORDER BY is_total, id', [$latestBatch]) : [];
$savedDate = $saved[0]['export_date'] ?? '';

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ringkasan-katalog-smcorner.csv"');
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Kategori', 'Jumlah Produk', 'Jumlah Varian', 'Total Stok', 'Nilai Inventori', 'Rata-rata Harga']);
    foreach ($saved as $s) {
        fputcsv($out, [$s['category_name'], $s['product_count'], $s['variant_count'], $s['stock_total'], $s['inventory_value'], $s['avg_price']]);
    }
    fclose($out); exit;
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-head">
    <div><h1 class="section-title"><i class="bi bi-cloud-arrow-down-fill" style="color:var(--smcorner)"></i> Import Katalog Lengkap dari Sheet</h1>
        <div class="page-sub">Satu link → semua tab: kategori, produk, varian ukuran/harga, riwayat stok, dan ringkasan</div></div>
    <a href="<?= base_url('/modules/smcorner/produk.php') ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-boxes"></i> Katalog Produk</a>
</div>

<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<?php if ($preview): ?>
<div class="card content-card mb-3">
    <div class="card-header d-flex justify-content-between flex-wrap gap-2">
        <span>Pratinjau: <b><?= e($preview['filename'] ?? 'Google Sheet') ?></b><?= $preview['export_date'] ? ' · Ekspor: '.e($preview['export_date']) : '' ?></span>
        <span class="badge bg-light text-dark">Batch: <?= e($preview['batch']) ?></span>
    </div>
    <div class="card-body">
        <div class="row g-2 mb-3">
            <div class="col-6 col-md-2"><div class="border rounded p-2 text-center"><b><?= angka($preview['counts']['categories']) ?></b><div class="small text-muted">Kategori</div></div></div>
            <div class="col-6 col-md-2"><div class="border rounded p-2 text-center"><b><?= angka($preview['counts']['products']) ?></b><div class="small text-muted">Produk</div></div></div>
            <div class="col-6 col-md-2"><div class="border rounded p-2 text-center"><b><?= angka($preview['counts']['variants']) ?></b><div class="small text-muted">Varian</div></div></div>
            <div class="col-6 col-md-2"><div class="border rounded p-2 text-center"><b><?= angka($preview['counts']['history']) ?></b><div class="small text-muted">Riwayat stok</div></div></div>
            <div class="col-6 col-md-2"><div class="border rounded p-2 text-center"><b><?= angka($preview['counts']['summary']) ?></b><div class="small text-muted">Ringkasan</div></div></div>
        </div>
        <?php if ($preview['warnings']): ?>
        <div class="alert alert-warning small"><b>Perhatian:</b><ul class="mb-0"><?php foreach ($preview['warnings'] as $w): ?><li><?= e($w) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>
        <?php if ($preview['products']): ?>
        <div class="small fw-bold mb-1">Contoh produk</div>
        <div class="table-responsive mb-3"><table class="table table-sm table-hover mb-0">
            <thead class="table-light"><tr><th>SKU</th><th>Nama</th><th>Kategori</th><th>Ukuran</th><th class="text-end">Harga Dasar</th><th class="text-end">Stok</th></tr></thead>
            <tbody><?php foreach (array_slice($preview['products'], 0, 8) as $p): ?><tr>
                <td class="small"><?= e($p['sku']) ?></td><td class="small"><?= e($p['name']) ?></td><td class="small"><?= e($p['category_name']) ?></td>
                <td class="small"><?= e($p['sizes']) ?></td><td class="text-end small"><?= rupiah($p['price']) ?></td><td class="text-end small"><?= angka($p['stock']) ?></td>
            </tr><?php endforeach; ?></tbody>
        </table></div>
        <?php endif; ?>
        <?php if ($preview['variants']): ?>
        <div class="small fw-bold mb-1">Contoh varian ukuran & harga</div>
        <div class="table-responsive mb-3"><table class="table table-sm mb-0">
            <thead class="table-light"><tr><th>SKU</th><th>Ukuran</th><th class="text-end">Stok</th><th class="text-end">Harga Satuan</th></tr></thead>
            <tbody><?php foreach (array_slice($preview['variants'], 0, 8) as $v): ?><tr>
                <td class="small"><?= e($v['product_key']) ?></td><td class="small"><?= e($v['size']) ?></td>
                <td class="text-end small"><?= angka($v['stock']) ?></td><td class="text-end small"><?= rupiah($v['price']) ?></td>
            </tr><?php endforeach; ?></tbody>
        </table></div>
        <?php endif; ?>
        <?php if ($preview['summary']): ?>
        <div class="small fw-bold mb-1">Ringkasan per kategori</div>
        <div class="table-responsive"><table class="table table-sm mb-0">
            <thead class="table-light"><tr><th>Kategori</th><th class="text-end">Produk</th><th class="text-end">Varian</th><th class="text-end">Stok</th><th class="text-end">Nilai Inventori</th></tr></thead>
            <tbody><?php foreach ($preview['summary'] as $s): ?><tr class="<?= $s['is_total'] ? 'fw-bold table-light' : '' ?>">
                <td class="small"><?= e($s['category']) ?></td><td class="text-end small"><?= angka($s['product_count']) ?></td>
                <td class="text-end small"><?= angka($s['variant_count']) ?></td><td class="text-end small"><?= angka($s['stock_total']) ?></td>
                <td class="text-end small"><?= rupiah($s['value']) ?></td>
            </tr><?php endforeach; ?></tbody>
        </table></div>
        <?php endif; ?>
        <div class="d-flex gap-2 mt-3">
            <form method="post"><?php csrf_field(); ?><input type="hidden" name="action" value="import"><button class="btn btn-success"><i class="bi bi-check2-circle"></i> Import Semua Tab</button></form>
            <form method="post"><?php csrf_field(); ?><input type="hidden" name="action" value="batal"><button class="btn btn-outline-secondary">Batal</button></form>
        </div>
    </div>
</div>
<?php else: ?>
<div class="row g-3"><div class="col-lg-7">
    <div class="card content-card"><div class="card-header">Ambil Data dari Google Sheets</div><div class="card-body">
        <form method="post" enctype="multipart/form-data">
            <?php csrf_field(); ?><input type="hidden" name="action" value="preview">
            <div class="mb-3"><label class="form-label small fw-semibold">Link Google Sheets</label>
                <input type="url" name="sheet_url" class="form-control" placeholder="https://docs.google.com/spreadsheets/d/.../edit?gid=..." value="<?= e($_POST['sheet_url'] ?? '') ?>">
                <div class="form-text small">Semua tab diambil (bukan hanya tab yang terbuka). Share = "Siapa pun yang memiliki link → Viewer".</div></div>
            <div class="text-center small text-muted my-2">— atau —</div>
            <div class="mb-3"><label class="form-label small fw-semibold">File CSV (tab ringkasan saja)</label><input type="file" name="csv" accept=".csv" class="form-control"></div>
            <button class="btn btn-success"><i class="bi bi-eye"></i> Pratinjau</button>
        </form>
    </div></div>
</div><div class="col-lg-5">
    <div class="card content-card"><div class="card-header">Yang diambil dari sheet</div><div class="card-body small">
        <ol class="mb-2">
            <li><b>Master Kategori</b> → tabel kategori (13 kategori)</li>
            <li><b>Katalog Produk</b> → 243 produk (SKU unik)</li>
            <li><b>Detail Stok &amp; Harga</b> → 337 varian ukuran + harga</li>
            <li><b>Riwayat Stok</b> → log perubahan stok</li>
            <li><b>Ringkasan &amp; Dashboard</b> → rekap per kategori</li>
        </ol>
        <p class="mb-0 text-muted">Produk yang dibuat manual tidak dihapus. Data dengan SKU sama diperbarui. Harga outlier disimpan apa adanya dan ditandai di pratinjau.</p>
    </div></div>
</div></div>
<?php endif; ?>

<?php if ($saved): ?>
<div class="card content-card mt-3"><div class="card-header d-flex justify-content-between"><span>Data Tersimpan — Ekspor: <?= e($savedDate ?: '-') ?></span><a class="btn btn-sm btn-success" href="<?= base_url('/modules/smcorner/import_katalog.php?export=csv') ?>"><i class="bi bi-file-earmark-excel"></i> Export CSV</a></div>
    <div class="table-responsive"><table class="table table-sm mb-0"><thead class="table-light"><tr><th>Kategori</th><th class="text-end">Produk</th><th class="text-end">Varian</th><th class="text-end">Stok</th><th class="text-end">Nilai Inventori</th><th class="text-end">Rata-rata</th></tr></thead>
        <tbody><?php foreach ($saved as $s): ?><tr class="<?= $s['is_total'] ? 'fw-bold table-light' : '' ?>"><td><?= e($s['category_name']) ?></td><td class="text-end"><?= angka($s['product_count']) ?></td><td class="text-end"><?= angka($s['variant_count']) ?></td><td class="text-end"><?= angka($s['stock_total']) ?></td><td class="text-end"><?= rupiah($s['inventory_value']) ?></td><td class="text-end"><?= rupiah($s['avg_price']) ?></td></tr><?php endforeach; ?></tbody>
    </table></div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
