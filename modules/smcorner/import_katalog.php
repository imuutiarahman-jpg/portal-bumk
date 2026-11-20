<?php
require_once __DIR__ . '/../../config/app.php';
require_login();
require_unit('smcorner');

$title = 'Import Katalog dari Sheet – SM Corner';
$activeUnit = 'smcorner';
$errors = [];
$preview = null;

function kat_money(string $s): float
{
    return (float) preg_replace('/\D/', '', $s);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'batal') {
        unset($_SESSION['sm_katalog_import']);
        flash_set('info', 'Pratinjau dibatalkan.');
        redirect('/modules/smcorner/import_katalog.php');
    }

    if ($action === 'preview') {
        $sheetUrl = trim((string) ($_POST['sheet_url'] ?? ''));
        $sourcePath = null; $sourceName = ''; $tempFile = null;

        if ($sheetUrl !== '') {
            [$exportUrl, $sourceName, $urlError] = sheet_export_url($sheetUrl);
            if ($urlError) {
                $errors[] = $urlError;
            } else {
                [$content, $fetchError] = sheet_fetch_csv($exportUrl);
                if ($fetchError) {
                    $errors[] = $fetchError;
                } else {
                    $tempFile = tempnam(sys_get_temp_dir(), 'sm_katalog_');
                    file_put_contents($tempFile, $content);
                    $sourcePath = $tempFile;
                }
            }
        } else {
            if (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
                $errors[] = 'Isi link Google Sheets atau pilih file CSV terlebih dahulu.';
            } elseif (!preg_match('/\.csv$/i', $_FILES['csv']['name'])) {
                $errors[] = 'File harus berformat .csv.';
            } else {
                $sourcePath = $_FILES['csv']['tmp_name'];
                $sourceName = $_FILES['csv']['name'];
            }
        }

        if ($errors || !$sourcePath) {
            if ($tempFile) @unlink($tempFile);
        } else {
            $handle = fopen($sourcePath, 'r');
            $firstLine = fgets($handle) ?: '';
            rewind($handle);
            $delim = (substr_count($firstLine, ',') >= substr_count($firstLine, ';')) ? ',' : ';';
            $allRows = [];
            while (($cols = fgetcsv($handle, 0, $delim)) !== false) {
                $cols = array_map('trim', $cols);
                if ($allRows === []) $cols[0] = preg_replace('/^\xEF\xBB\xBF/', '', $cols[0] ?? '');
                $allRows[] = $cols;
            }
            fclose($handle);
            if ($tempFile) @unlink($tempFile);

            $exportDate = '';
            foreach ($allRows as $r) {
                $joined = implode(' ', $r);
                if (stripos($joined, 'Tanggal Ekspor:') !== false && preg_match('/Tanggal Ekspor:\s*(.+)$/i', $joined, $m)) {
                    $exportDate = trim($m[1]);
                    break;
                }
            }

            $catIdx = null; $headerFound = false;
            foreach ($allRows as $r) {
                foreach ($r as $i => $c) {
                    if (strtoupper($c) === 'KATEGORI') { $catIdx = $i; $headerFound = true; break 2; }
                }
            }

            $rows = [];
            if (!$headerFound) {
                $errors[] = 'Header "Kategori" tidak ditemukan. Pastikan sheet berisi blok REKAPITULASI PRODUK & STOK PER KATEGORI.';
            } else {
                $started = false;
                foreach ($allRows as $r) {
                    $joined = implode(' ', $r);
                    if (!$started) {
                        if (stripos($joined, 'REKAPITULASI PRODUK & STOK PER KATEGORI') !== false) $started = true;
                        continue;
                    }
                    if (strtoupper(trim($r[$catIdx] ?? '')) === 'KATEGORI') continue; // baris header
                    $cat = trim((string) ($r[$catIdx] ?? ''));
                    if ($cat === '') continue;
                    $isTotal = stripos($cat, 'TOTAL KESELURUHAN') !== false;
                    $rows[] = [
                        'category' => $isTotal ? 'TOTAL KESELURUHAN' : $cat,
                        'product_count' => kat_money($r[$catIdx + 1] ?? '0'),
                        'variant_count' => kat_money($r[$catIdx + 2] ?? '0'),
                        'stock_total'   => kat_money($r[$catIdx + 3] ?? '0'),
                        'value'         => kat_money($r[$catIdx + 4] ?? '0'),
                        'avg'           => kat_money($r[$catIdx + 5] ?? '0'),
                        'is_total'      => $isTotal,
                    ];
                }
                if (!$rows) $errors[] = 'Tidak ada baris kategori yang bisa dibaca.';
            }

            if (!$errors && $rows) {
                $batch = strtolower(preg_replace('/[^a-z0-9]+/i', '-', pathinfo($sourceName, PATHINFO_FILENAME)));
                $_SESSION['sm_katalog_import'] = [
                    'batch' => $batch, 'filename' => $sourceName,
                    'export_date' => $exportDate, 'rows' => $rows,
                ];
                $preview = $_SESSION['sm_katalog_import'];
            }
        }
    }

    if ($action === 'import') {
        $data = $_SESSION['sm_katalog_import'] ?? null;
        if (!$data || empty($data['rows'])) {
            $errors[] = 'Tidak ada data pratinjau. Silakan upload ulang.';
        } else {
            $pdo = db(); $pdo->beginTransaction();
            try {
                run('DELETE FROM sm_catalog_summary WHERE import_batch = ?', [$data['batch']]);
                foreach ($data['rows'] as $r) {
                    run('INSERT INTO sm_catalog_summary (import_batch,export_date,category_name,product_count,variant_count,stock_total,inventory_value,avg_price,is_total) VALUES (?,?,?,?,?,?,?,?,?)', [
                        $data['batch'], $data['export_date'], $r['category'], $r['product_count'],
                        $r['variant_count'], $r['stock_total'], $r['value'], $r['avg'], $r['is_total'] ? 1 : 0,
                    ]);
                    if (!$r['is_total'] && !val('SELECT COUNT(*) FROM sm_categories WHERE name=?', [$r['category']])) {
                        run('INSERT INTO sm_categories (name) VALUES (?)', [$r['category']]);
                    }
                }
                $pdo->commit();
                activity('smcorner', 'Import katalog sheet: ' . $data['filename'] . ' (' . count($data['rows']) . ' baris)');
                unset($_SESSION['sm_katalog_import']);
                flash_set('success', 'Import katalog berhasil: ' . count($data['rows']) . ' baris tersimpan.');
                redirect('/modules/smcorner/import_katalog.php');
            } catch (Throwable $e) {
                $pdo->rollBack();
                $errors[] = 'Gagal mengimpor: ' . $e->getMessage();
            }
        }
    }
} else {
    $preview = $_SESSION['sm_katalog_import'] ?? null;
}

$latestBatch = val('SELECT import_batch FROM sm_catalog_summary ORDER BY id DESC LIMIT 1');
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
    <div><h1 class="section-title"><i class="bi bi-cloud-arrow-down-fill" style="color:var(--smcorner)"></i> Import Katalog dari Sheet</h1><div class="page-sub">Ambil ringkasan kategori & inventori dari Google Sheets</div></div>
    <a href="<?= base_url('/modules/smcorner/produk.php') ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-boxes"></i> Katalog Produk</a>
</div>

<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<?php if ($preview): ?>
<div class="card content-card mb-3">
    <div class="card-header d-flex justify-content-between flex-wrap gap-2">
        <span>Pratinjau: <b><?= e($preview['filename']) ?></b> · <?= count($preview['rows']) ?> baris<?= $preview['export_date'] ? ' · Ekspor: '.e($preview['export_date']) : '' ?></span>
        <span class="badge bg-light text-dark">Batch: <?= e($preview['batch']) ?></span>
    </div>
    <div class="card-body">
        <div class="table-responsive"><table class="table table-sm table-hover mb-0">
            <thead class="table-light"><tr><th>Kategori</th><th class="text-end">Produk</th><th class="text-end">Varian</th><th class="text-end">Stok</th><th class="text-end">Nilai Inventori</th><th class="text-end">Rata-rata</th></tr></thead>
            <tbody><?php foreach ($preview['rows'] as $r): ?><tr class="<?= $r['is_total'] ? 'fw-bold table-light' : '' ?>">
                <td><?= e($r['category']) ?></td><td class="text-end"><?= angka($r['product_count']) ?></td><td class="text-end"><?= angka($r['variant_count']) ?></td>
                <td class="text-end"><?= angka($r['stock_total']) ?></td><td class="text-end"><?= rupiah($r['value']) ?></td><td class="text-end"><?= rupiah($r['avg']) ?></td>
            </tr><?php endforeach; ?></tbody>
        </table></div>
        <div class="d-flex gap-2 mt-3">
            <form method="post"><?php csrf_field(); ?><input type="hidden" name="action" value="import"><button class="btn btn-success"><i class="bi bi-check2-circle"></i> Import <?= count($preview['rows']) ?> Baris</button></form>
            <form method="post"><?php csrf_field(); ?><input type="hidden" name="action" value="batal"><button class="btn btn-outline-secondary">Batal</button></form>
        </div>
    </div>
</div>
<?php else: ?>
<div class="row g-3"><div class="col-lg-7">
    <div class="card content-card"><div class="card-header">Ambil Data</div><div class="card-body">
        <form method="post" enctype="multipart/form-data">
            <?php csrf_field(); ?><input type="hidden" name="action" value="preview">
            <div class="mb-3"><label class="form-label small fw-semibold">Link Google Sheets</label><input type="url" name="sheet_url" class="form-control" placeholder="https://docs.google.com/spreadsheets/d/.../edit#gid=..."><div class="form-text small">Sheet harus di-share "Siapa pun yang memiliki link → Viewer". Jika diisi, link yang dipakai.</div></div>
            <div class="text-center small text-muted my-2">— atau —</div>
            <div class="mb-3"><label class="form-label small fw-semibold">File CSV</label><input type="file" name="csv" accept=".csv" class="form-control"></div>
            <button class="btn btn-success"><i class="bi bi-eye"></i> Pratinjau</button>
        </form>
    </div></div>
</div><div class="col-lg-5">
    <div class="card content-card"><div class="card-header">Yang akan diambil</div><div class="card-body small">
        <p class="mb-2">Baris pada blok <b>REKAPITULASI PRODUK & STOK PER KATEGORI</b>: nama kategori, jumlah produk, jumlah varian, total unit stok, nilai inventori, dan rata-rata harga.</p>
        <p class="mb-0 text-muted">Catatan: sheet hanya berisi ringkasan per kategori, bukan daftar per produk. Jadi yang dibuat adalah ringkasan + nama kategori, bukan 243 produk.</p>
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
