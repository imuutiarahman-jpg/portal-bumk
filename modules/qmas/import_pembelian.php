<?php
require_once __DIR__ . '/../../config/app.php';
require_login();
require_unit('qmas');

$title = 'Import Stok Masuk – Qmas';
$activeUnit = 'qmas';
$errors = [];
$preview = null;

// Naikkan bila aturan parsing diubah supaya pratinjau lama ditolak.
define('IMPORT_PEMBELIAN_VERSION', '2026-11-25-kartu-stok-v1');
define('PEMBELIAN_BATCH', 'KARTU-STOK-2026');

$skuOrder  = ['QMS-G19', 'QMS-G22', 'QMS-330', 'QMS-B60', 'QMS-B15'];
$skuLabels = ['GL 19L', 'Gelas / 220ML', '330ML', '600ML', '1.5L'];

/* ---------- Helper ---------- */

function imp2_int(string $s): int
{
    return (int) preg_replace('/\D/', '', trim($s));
}

function imp2_date(string $s): ?string
{
    $s = trim($s, " \t\n\r\0\x0B`'\"");
    if ($s === '') return null;
    $s = preg_replace('#/+#', '/', $s);
    $s = preg_replace('/\s+\d{1,2}:\d{2}(?::\d{2})?\s*$/', '', $s);

    if (preg_match('/^\d{5}$/', $s)) {
        return date('Y-m-d H:i:s', ((int) $s - 25569) * 86400);
    }
    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $s, $m)) {
        return $m[1] . ' 00:00:00';
    }
    if (preg_match('/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/', $s, $m)) {
        return sprintf('%04d-%02d-%02d 00:00:00', (int) $m[1], (int) $m[2], (int) $m[3]);
    }
    if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2,4})$/', $s, $m)) {
        $y = (int) $m[3];
        if ($y < 100) $y += 2000;
        return sprintf('%04d-%02d-%02d 00:00:00', $y, (int) $m[2], (int) $m[1]);
    }

    $months = [
        'jan' => 1, 'januari' => 1, 'feb' => 2, 'februari' => 2, 'mar' => 3, 'maret' => 3,
        'apr' => 4, 'april' => 4, 'mei' => 5, 'jun' => 6, 'juni' => 6, 'jul' => 7, 'juli' => 7,
        'agu' => 8, 'agus' => 8, 'ags' => 8, 'agustus' => 8, 'sep' => 9, 'sept' => 9,
        'september' => 9, 'okt' => 10, 'okto' => 10, 'oktober' => 10, 'nov' => 11, 'nop' => 11,
        'november' => 11, 'des' => 12, 'desember' => 12,
    ];
    $norm = preg_replace('/[\/\-]/', ' ', $s);
    if (preg_match('/^(\d{1,2})\s*([A-Za-z]+)\.?(?:\s+(\d{2,4}))?$/u', $norm, $m)) {
        $key = strtolower($m[2]);
        if (!isset($months[$key])) return null;
        $y = (int) ($m[3] ?? 0);
        if ($y < 100) $y += 2000;
        return sprintf('%04d-%02d-%02d 00:00:00', $y, $months[$key], (int) $m[1]);
    }
    return null;
}

function imp2_sheet_url(string $url): array
{
    $url = trim($url);
    if ($url === '') return [null, '', ''];
    $parts = parse_url($url);
    if (!$parts || strtolower($parts['scheme'] ?? '') !== 'https') {
        return [null, '', 'Link harus diawali https://'];
    }
    if (!in_array(strtolower($parts['host'] ?? ''), ['docs.google.com', 'www.docs.google.com'], true)) {
        return [null, '', 'Hanya link Google Sheets (docs.google.com) yang didukung.'];
    }
    if (!preg_match('#/spreadsheets/d/([A-Za-z0-9_-]+)#', $parts['path'] ?? '', $m)) {
        return [null, '', 'ID sheet tidak ditemukan dalam link.'];
    }
    $gid = '0';
    foreach (['query', 'fragment'] as $key) {
        if (!empty($parts[$key]) && preg_match('/(?:^|&)gid=(\d+)/', $parts[$key], $g)) { $gid = $g[1]; break; }
    }
    return [
        'https://docs.google.com/spreadsheets/d/' . $m[1] . '/export?format=csv&gid=' . $gid,
        'kartu-stok-' . $m[1] . '-gid' . $gid,
        '',
    ];
}

function imp2_fetch_csv(string $exportUrl): array
{
    if (!function_exists('curl_init')) return [null, 'cURL tidak tersedia di server.'];
    $ch = curl_init($exportUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 20,
        CURLOPT_USERAGENT => 'Portal-BUMK-UMGO-StokMasuk/1.0',
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $content = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($content === false) return [null, 'Gagal mengambil sheet: ' . $err];
    if ($code !== 200) return [null, 'Google mengembalikan HTTP ' . $code . '.'];
    if (strlen($content) > 10 * 1024 * 1024) return [null, 'CSV terlalu besar (maksimal 10 MB).'];
    if (preg_match('/^\s*(<!DOCTYPE|<html|<\?xml)/i', $content)) {
        return [null, 'Sheet tidak bisa diakses. Pastikan Sharing = "Siapa pun yang memiliki link" (Viewer).'];
    }
    if (trim($content) === '') return [null, 'Sheet kosong.'];
    return [$content, ''];
}

/** Parse kartu stok: kolom TANGGAL + 5 kolom Stok Masuk setelahnya. */
function imp2_parse(string $content, array $skuOrder): array
{
    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
    $content = str_replace(["\r\n", "\r"], "\n", $content);
    $lines = explode("\n", $content);
    $first = $lines[0] ?? '';
    $delim = ',';
    $score = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")];
    arsort($score);
    $best = array_key_first($score);
    if ($score[$best] > 0) $delim = $best;

    $dateCol = null;
    $rows = [];
    $skipped = [];
    $lineNo = 0;

    foreach ($lines as $line) {
        $lineNo++;
        if (trim($line) === '') continue;
        $cols = str_getcsv($line, $delim);
        $cols = array_map(fn($c) => trim((string) $c), $cols);

        if ($dateCol === null) {
            foreach ($cols as $i => $c) {
                if (strtoupper(str_replace(' ', '', $c)) === 'TANGGAL') { $dateCol = $i; break; }
            }
            continue;
        }

        $raw = $cols[$dateCol] ?? '';
        if ($raw === '') continue;
        $up = strtoupper($raw);
        if (str_contains($up, 'JUMLAH') || str_contains($up, 'BULAN') || str_contains($up, 'TOTAL') || str_contains($up, 'PERIODE')) {
            continue;
        }
        $date = imp2_date($raw);
        if (!$date) {
            $skipped[] = "Baris $lineNo: tanggal '$raw' tidak dikenali";
            continue;
        }
        $qtys = [];
        for ($i = 0; $i < 5; $i++) $qtys[] = imp2_int($cols[$dateCol + 1 + $i] ?? '');
        if (!array_sum($qtys)) continue;

        $items = [];
        foreach ($skuOrder as $i => $sku) {
            if ($qtys[$i] > 0) $items[] = ['sku' => $sku, 'qty' => $qtys[$i]];
        }
        $rows[] = ['date' => $date, 'items' => $items];
    }

    if ($dateCol === null) {
        $skipped[] = 'Header TANGGAL tidak ditemukan. Pastikan sheet kartu stok memiliki kolom TANGGAL.';
    }
    return [$rows, $skipped];
}

/* ---------- Proses ---------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'batal') {
        unset($_SESSION['qmas_pembelian_import']);
        flash_set('info', 'Pratinjau dibatalkan.');
        redirect('/modules/qmas/import_pembelian.php');
    }

    if ($action === 'preview') {
        $sheetUrl = trim((string) ($_POST['sheet_url'] ?? ''));
        $paste = (string) ($_POST['paste_data'] ?? '');
        $content = null;
        $sourceName = '';

        if ($sheetUrl !== '') {
            [$exportUrl, $sourceName, $urlError] = imp2_sheet_url($sheetUrl);
            if ($urlError) {
                $errors[] = $urlError;
            } else {
                [$content, $fetchError] = imp2_fetch_csv($exportUrl);
                if ($fetchError) $errors[] = $fetchError;
            }
        } elseif (trim($paste) !== '') {
            $content = str_replace(["\r\n", "\r"], "\n", $paste);
            $sourceName = 'paste-kartu-stok';
        } elseif (isset($_FILES['csv']) && $_FILES['csv']['error'] === UPLOAD_ERR_OK) {
            if (!preg_match('/\.csv$/i', $_FILES['csv']['name'])) {
                $errors[] = 'File harus berformat .csv.';
            } else {
                $content = @file_get_contents($_FILES['csv']['tmp_name']);
                $sourceName = $_FILES['csv']['name'];
            }
        } else {
            $errors[] = 'Isi link Google Sheets, tempel data, atau pilih file CSV.';
        }

        if ($content !== null && !$errors) {
            [$rows, $skipped] = imp2_parse($content, $skuOrder);
            if (!$rows) {
                $errors[] = 'Tidak ada baris Stok Masuk yang bisa dibaca.' . ($skipped ? ' ' . e(implode(' · ', array_slice($skipped, 0, 5))) : '');
            } else {
                $totals = array_fill(0, 5, 0);
                foreach ($rows as $r) {
                    foreach ($r['items'] as $it) {
                        $idx = array_search($it['sku'], $skuOrder, true);
                        if ($idx !== false) $totals[$idx] += $it['qty'];
                    }
                }
                $dup = 0;
                foreach ($rows as $r) {
                    $dup += (int) val('SELECT COUNT(*) FROM qmas_purchases WHERE DATE(purchase_date)=?', [substr($r['date'], 0, 10)]);
                }
                $_SESSION['qmas_pembelian_import'] = [
                    'version' => IMPORT_PEMBELIAN_VERSION,
                    'batch' => PEMBELIAN_BATCH,
                    'filename' => $sourceName,
                    'rows' => $rows,
                    'skipped' => $skipped,
                    'totals' => $totals,
                    'dup' => $dup,
                ];
                $preview = $_SESSION['qmas_pembelian_import'];
            }
        }
    }

    if ($action === 'import') {
        $data = $_SESSION['qmas_pembelian_import'] ?? null;
        if (!$data || empty($data['rows'])) {
            $errors[] = 'Tidak ada data pratinjau. Silakan lakukan Pratinjau ulang.';
        } elseif (($data['version'] ?? '') !== IMPORT_PEMBELIAN_VERSION) {
            $errors[] = 'Pratinjau berasal dari aturan lama. Klik Batal lalu Pratinjau ulang.';
        } else {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                run('DELETE FROM qmas_purchases WHERE invoice_no=?', [PEMBELIAN_BATCH]);

                $map = [];
                foreach (all('SELECT * FROM qmas_products') as $p) $map[$p['sku']] = $p;
                if (!isset($map['QMS-G22']) && isset($map['QMS-240'])) $map['QMS-G22'] = $map['QMS-240'];

                $seq = 0;
                foreach ($data['rows'] as $r) {
                    $amount = 0.0;
                    $items = [];
                    foreach ($r['items'] as $it) {
                        $p = $map[$it['sku']] ?? null;
                        if (!$p) continue;
                        $price = (float) $p['cost'];
                        $items[] = ['product' => $p, 'qty' => $it['qty'], 'price' => $price];
                        $amount += $it['qty'] * $price;
                    }
                    if (!$items) continue;
                    $seq++;
                    $no = 'PO-KARTU-' . date('Ymd', strtotime($r['date'])) . '-' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
                    run('INSERT INTO qmas_purchases
                        (purchase_no,purchase_date,invoice_no,supplier_name,payment_status,bank,total_amount,notes,user_id,created_at)
                        VALUES (?,?,?,?,?,?,?,?,?,NOW())', [
                        $no, $r['date'], PEMBELIAN_BATCH, 'Import Kartu Stok Qmas', 'tunai', null,
                        $amount, 'Stok Masuk kartu stok Qmas 2026', current_user()['id'],
                    ]);
                    $purchaseId = last_id();
                    foreach ($items as $it) {
                        run('INSERT INTO qmas_purchase_items (purchase_id,product_id,quantity,unit_price,subtotal) VALUES (?,?,?,?,?)', [
                            $purchaseId, $it['product']['id'], $it['qty'], $it['price'], $it['qty'] * $it['price'],
                        ]);
                    }
                }
                $pdo->commit();
                qmas_recalc_stock();
                activity('qmas', 'Import Stok Masuk kartu: ' . $data['filename'] . ' (' . count($data['rows']) . ' tanggal)');
                unset($_SESSION['qmas_pembelian_import']);
                flash_set('success', count($data['rows']) . ' tanggal stok masuk berhasil diimport. Stok sudah dihitung ulang.');
                redirect('/modules/qmas/stok.php');
            } catch (Throwable $e) {
                $pdo->rollBack();
                $errors[] = 'Gagal mengimport: ' . $e->getMessage();
            }
        }
    }
} else {
    $preview = $_SESSION['qmas_pembelian_import'] ?? null;
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="section-title mb-1"><i class="bi bi-box-arrow-in-down" style="color:var(--qmas)"></i> Import Stok Masuk (Kartu Stok)</h1>
        <div class="text-muted small">Ambil kolom <b>TANGGAL</b> dan <b>Stok Masuk</b> dari kartu stok Qmas, lalu jadikan nota pembelian bertanggal.</div>
    </div>
    <a href="<?= base_url('/modules/qmas/pembelian.php') ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-cart-plus"></i> Pembelian Manual</a>
</div>

<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<div class="alert alert-light small">
    <b>Aturan:</b> stok awal = <code>opening_stock</code> per 1 Jan 2026 (angka penyeimbang selisih penjualan), pembelian &amp; penjualan dihitung mulai setelah tanggal itu. Harga nota memakai <b>HPP produk yang sedang berlaku</b> agar laporan laba tidak berubah. Import ulang dengan batch <b><?= e(PEMBELIAN_BATCH) ?></b> akan menimpa nota batch yang sama.
</div>

<?php if ($preview): ?>
<div class="card content-card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span>Pratinjau: <b><?= e($preview['filename']) ?></b> · <?= count($preview['rows']) ?> tanggal · batch <b><?= e($preview['batch']) ?></b></span>
        <span class="badge bg-primary">Mode timpa batch sama</span>
    </div>
    <div class="card-body">
        <?php if (!empty($preview['skipped'])): ?>
            <div class="alert alert-warning small py-2"><b><?= count($preview['skipped']) ?> baris dilewati:</b><br><?= e(implode(' · ', array_slice($preview['skipped'], 0, 8))) ?></div>
        <?php endif; ?>
        <?php if (!empty($preview['dup'])): ?>
            <div class="alert alert-info small py-2">Ada <b><?= (int) $preview['dup'] ?></b> nota pembelian lain pada tanggal-tanggal tersebut. Jika itu nota manual yang Anda input sebelumnya, hapus dulu agar tidak dobel—import ini hanya menimpa nota dengan batch <b><?= e(PEMBELIAN_BATCH) ?></b>.</div>
        <?php endif; ?>
        <div class="alert alert-success small py-2">
            <b>Total Stok Masuk:</b>
            <?php foreach ($skuLabels as $i => $label): ?><?= e($label) ?> <b><?= angka($preview['totals'][$i]) ?></b><?= $i < 4 ? ' · ' : '' ?><?php endforeach; ?>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light"><tr><th>Tanggal</th><?php foreach ($skuLabels as $l): ?><th class="text-end"><?= e($l) ?></th><?php endforeach; ?></tr></thead>
                <tbody>
                <?php foreach (array_slice($preview['rows'], 0, 40) as $r):
                    $q = array_fill(0, 5, 0);
                    foreach ($r['items'] as $it) { $idx = array_search($it['sku'], $skuOrder, true); if ($idx !== false) $q[$idx] = $it['qty']; }
                ?>
                    <tr><td class="small"><?= tgl($r['date']) ?></td><?php foreach ($q as $v): ?><td class="text-end small"><?= $v ? angka($v) : '-' ?></td><?php endforeach; ?></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (count($preview['rows']) > 40): ?><div class="small text-muted mt-2">Menampilkan 40 tanggal pertama dari <?= count($preview['rows']) ?>.</div><?php endif; ?>
        <div class="d-flex gap-2 mt-3">
            <form method="post"><?php csrf_field(); ?><input type="hidden" name="action" value="import"><button class="btn btn-primary"><i class="bi bi-check2-circle"></i> Import <?= count($preview['rows']) ?> Tanggal</button></form>
            <form method="post"><?php csrf_field(); ?><input type="hidden" name="action" value="batal"><button class="btn btn-outline-secondary">Batal</button></form>
        </div>
    </div>
</div>
<?php else: ?>
<div class="row g-3">
    <div class="col-lg-7">
        <div class="card content-card">
            <div class="card-header"><i class="bi bi-link-45deg text-success"></i> Ambil Data Kartu Stok</div>
            <div class="card-body">
                <form method="post" enctype="multipart/form-data">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="preview">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Link Google Sheets (tab Kartu Stok)</label>
                        <input type="url" name="sheet_url" class="form-control" placeholder="https://docs.google.com/spreadsheets/d/.../edit?gid=468791146">
                        <div class="form-text small">Sheet harus di-share "Siapa pun yang memiliki link → Viewer". Jika diisi, link yang dipakai.</div>
                    </div>
                    <div class="text-center small text-muted my-2">— atau —</div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Tempel cell dari Sheets</label>
                        <textarea name="paste_data" class="form-control" rows="5" placeholder="TANGGAL &lt;tab&gt; Stok Masuk ..."></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">File CSV</label>
                        <input type="file" name="csv" accept=".csv" class="form-control">
                    </div>
                    <button class="btn btn-primary"><i class="bi bi-eye"></i> Pratinjau</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card content-card">
            <div class="card-header">Format yang dibaca</div>
            <div class="card-body small">
                <p class="mb-2">Baris dengan kolom <b>TANGGAL</b>, lalu 5 kolom berikutnya adalah <b>Stok Masuk</b>:</p>
                <ol class="mb-2">
                    <li>GL 19L</li><li>Gelas / 220ML</li><li>330ML</li><li>600ML</li><li>1.5L</li>
                </ol>
                <div class="text-muted">Baris <code>BULAN …</code>, <code>JUMLAH</code>, dan tanggal tanpa stok masuk dilewati otomatis. Format tanggal yang diterima: <code>3-Nov-26</code>, <code>3 Nov 2026</code>, <code>dd/mm/yyyy</code>, <code>yyyy-mm-dd</code>.</div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
