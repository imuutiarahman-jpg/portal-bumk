<?php
require_once __DIR__ . '/../../config/app.php';
require_login();
require_unit('qmas');

$title = 'Import Transaksi CSV – Qmas';
$activeUnit = 'qmas';
$errors = [];
$preview = null;

/* ---------- Parser helpers ---------- */
function imp_money(string $s): float
{
    $s = trim($s);
    if ($s === '') return 0.0;
    $s = preg_replace('/[^\d,.\-]/', '', $s);
    if (strpos($s, ',') !== false && strpos($s, '.') !== false) {
        $s = strrpos($s, ',') > strrpos($s, '.')
            ? str_replace('.', '', str_replace(',', '.', $s))
            : str_replace(',', '', $s);
    } elseif (preg_match('/\.\d{3}$/', $s)) {
        $s = str_replace('.', '', $s);
    } else {
        $s = str_replace(',', '.', $s);
    }
    return (float) $s;
}

function imp_int(string $s): int
{
    return (int) preg_replace('/\D/', '', trim($s));
}

function imp_date(string $s): ?string
{
    $s = trim($s, " \t\n\r\0\x0B`'\""); // buang backtick/tanda kutip dari Sheets
    if ($s === '') return null;
    if (preg_match('/^\d{5}$/', $s)) {
        return date('Y-m-d H:i:s', ((int) $s - 25569) * 86400);
    }
    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $s, $m)) {
        return $m[1] . ' 00:00:00';
    }
    if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2,4})$/', $s, $m)) {
        $y = (int) $m[3];
        if ($y < 100) $y += 2000;
        return sprintf('%04d-%02d-%02d 00:00:00', $y, (int) $m[2], (int) $m[1]);
    }
    if (preg_match('/^(\d{1,2})\s*([A-Za-z]+)\.?(?:\s+(\d{2,4}))?$/u', $s, $m)) {
        $months = [
            'jan' => 1, 'januari' => 1, 'feb' => 2, 'februari' => 2, 'mar' => 3, 'maret' => 3,
            'apr' => 4, 'april' => 4, 'mei' => 5, 'may' => 5, 'jun' => 6, 'juni' => 6,
            'jul' => 7, 'juli' => 7, 'agu' => 8, 'agus' => 8, 'ags' => 8, 'agustus' => 8, 'aug' => 8,
            'sep' => 9, 'sept' => 9, 'september' => 9, 'okt' => 10, 'okto' => 10, 'oktober' => 10, 'oct' => 10,
            'nov' => 11, 'nop' => 11, 'november' => 11, 'des' => 12, 'desember' => 12, 'dec' => 12,
        ];
        $key = strtolower($m[2]);
        if (!isset($months[$key])) return null;
        $y = !empty($m[3]) ? (int) $m[3] : (int) date('Y');
        if ($y < 100) $y += 2000;
        return sprintf('%04d-%02d-%02d 00:00:00', $y, $months[$key], (int) $m[1]);
    }
    return null;
}

function imp_bank(string $ket): ?string
{
    $k = strtoupper($ket);
    if (str_contains($k, 'BSI') || str_contains($k, 'BSUI')) return 'BSI';
    if (str_contains($k, 'MUAMALAT')) return 'MUAMALAT';
    return null;
}

function imp_detect_delimiter(string $firstLine): string
{
    $score = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
    arsort($score);
    $best = array_key_first($score);
    return $score[$best] > 0 ? $best : ',';
}

function imp_products(): array
{
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (all('SELECT * FROM qmas_products WHERE is_active=1') as $p) $map[$p['sku']] = $p;
    }
    return $map;
}

/** Ubah URL share Google Sheets menjadi URL export CSV. */
function imp_sheet_export_url(string $url): array
{
    $url = trim($url);
    if ($url === '') return [null, '', ''];
    $parts = parse_url($url);
    if (!$parts || strtolower($parts['scheme'] ?? '') !== 'https') {
        return [null, '', 'Link harus diawali https://'];
    }
    $host = strtolower($parts['host'] ?? '');
    if (!in_array($host, ['docs.google.com', 'www.docs.google.com'], true)) {
        return [null, '', 'Hanya link Google Sheets (docs.google.com) yang didukung.'];
    }
    if (!preg_match('#/spreadsheets/d/([A-Za-z0-9_-]+)#', $parts['path'] ?? '', $m)) {
        return [null, '', 'ID sheet tidak ditemukan dalam link.'];
    }
    $id = $m[1];
    $gid = '0';
    foreach (['query', 'fragment'] as $key) {
        if (!empty($parts[$key]) && preg_match('/(?:^|&)gid=(\d+)/', $parts[$key], $g)) {
            $gid = $g[1];
            break;
        }
    }
    $export = 'https://docs.google.com/spreadsheets/d/' . $id . '/export?format=csv&gid=' . $gid;
    $label = 'google-sheet-' . $id . '-gid' . $gid;
    return [$export, $label, ''];
}

/** Ambil isi CSV dari Google Sheets dengan cURL. */
function imp_fetch_sheet_csv(string $exportUrl): array
{
    if (!function_exists('curl_init')) {
        return [null, 'cURL tidak tersedia di server XAMPP.'];
    }
    $ch = curl_init($exportUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_USERAGENT      => 'Portal-BUMK-UMGO-CSV-Import/1.0',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $content = curl_exec($ch);
    $code    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err     = curl_error($ch);
    curl_close($ch);

    if ($content === false) return [null, 'Gagal mengambil sheet: ' . $err];
    if ($code !== 200) return [null, 'Google mengembalikan HTTP ' . $code . '.'];
    if (strlen($content) > 10 * 1024 * 1024) return [null, 'CSV terlalu besar (maksimal 10 MB).'];
    $head = ltrim(substr($content, 0, 600));
    if (stripos($head, '<!DOCTYPE html') === 0 || stripos($head, '<html') === 0 || stripos($head, '<?xml') === 0) {
        return [null, 'Sheet tidak bisa diakses. Pastikan Sharing = "Siapa pun yang memiliki link" (Viewer).'];
    }
    if (trim($content) === '') return [null, 'Sheet kosong.'];
    return [$content, ''];
}

// Offset relatif terhadap kolom TANGGAL (sesuai sheet asli: tiap produk punya QTT, PRICE, HPP)
$productCols = [
    ['qty' => 4,  'price' => 5,  'hpp' => 6,  'sku' => 'QMS-G19'],
    ['qty' => 7,  'price' => 8,  'hpp' => 9,  'sku' => 'QMS-240'],
    ['qty' => 10, 'price' => 11, 'hpp' => 12, 'sku' => 'QMS-330'],
    ['qty' => 13, 'price' => 14, 'hpp' => 15, 'sku' => 'QMS-B60'],
    ['qty' => 16, 'price' => 17, 'hpp' => 18, 'sku' => 'QMS-B15'],
];

/* ---------- Proses ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'preview') {
        $sheetUrl = trim((string) ($_POST['sheet_url'] ?? ''));
        $sourcePath = null; $sourceName = ''; $tempFile = null;

        if ($sheetUrl !== '') {
            [$exportUrl, $sourceName, $urlError] = imp_sheet_export_url($sheetUrl);
            if ($urlError) {
                $errors[] = $urlError;
            } else {
                [$content, $fetchError] = imp_fetch_sheet_csv($exportUrl);
                if ($fetchError) {
                    $errors[] = $fetchError;
                } else {
                    $tempFile = tempnam(sys_get_temp_dir(), 'qmas_sheet_');
                    file_put_contents($tempFile, $content);
                    $sourcePath = $tempFile;
                }
            }
        } else {
            if (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
                $errors[] = 'Isi link Google Sheets atau pilih file CSV terlebih dahulu.';
            } elseif (!preg_match('/\.csv$/i', $_FILES['csv']['name'])) {
                $errors[] = 'File harus berformat .csv (Save as CSV dari Google Sheets / Excel).';
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
            $delim = imp_detect_delimiter($firstLine);

            $rows = []; $skipped = []; $warnings = []; $headerIndex = null; $base = 0; $lineNo = 0; $lastDateRaw = '';
            while (($cols = fgetcsv($handle, 0, $delim)) !== false) {
                $lineNo++;
                if ($lineNo === 1) $cols[0] = preg_replace('/^\xEF\xBB\xBF/', '', $cols[0] ?? '');
                $cols = array_map('trim', $cols);

                if ($headerIndex === null) {
                    $joined = strtoupper(implode(' ', $cols));
                    if (str_contains($joined, 'TANGGAL') && str_contains($joined, 'NAMA PELANGGAN')) {
                        $headerIndex = $lineNo;
                        foreach ($cols as $i => $c) {
                            if (strtoupper($c) === 'TANGGAL') { $base = $i; break; }
                        }
                    }
                    continue;
                }

                $get = function (int $i) use ($cols, $base): string {
                    return (string) ($cols[$base + $i] ?? '');
                };

                $joined = implode('', $cols);
                if ($joined === '') continue;
                if (stripos($get(0), 'BULAN') === 0) continue;
                // Baris sub-header (QTT, PRICE, HPP, LABA/RUGI) bukan transaksi
                if (str_contains(strtoupper($joined), 'QTT') && str_contains(strtoupper($joined), 'PRICE')) continue;
                if ($get(0) === '' && $get(1) === '') continue;

                $dateRaw = $get(0);
                if ($dateRaw !== '') $lastDateRaw = $dateRaw;
                $date = imp_date($lastDateRaw);
                $nama = $get(1);
                if ($nama === '') continue;
                if (!$date) { $skipped[] = "Baris $lineNo: tanggal '$lastDateRaw' tidak dikenali"; continue; }

                $products = imp_products();
                $items = []; $total = 0.0; $hpp = 0.0;
                foreach ($productCols as $pc) {
                    $qty = imp_int($get($pc['qty']));
                    if ($qty < 1) continue;
                    $price = imp_money($get($pc['price']));
                    $product = $products[$pc['sku']] ?? null;
                    if (!$product) { $skipped[] = "Baris $lineNo: produk {$pc['sku']} belum ada"; continue; }
                    if ($price <= 0) $price = (float) $product['price'];
                    $cost = $pc['hpp'] !== null ? imp_money($get($pc['hpp'])) : (float) $product['cost'];
                    if ($cost > 0) $hpp = $cost;
                    $subtotal = $qty * $price;
                    $items[] = ['product' => $product, 'qty' => $qty, 'price' => $price, 'cost' => $cost, 'subtotal' => $subtotal];
                    $total += $subtotal;
                }
                if (!$items) { $skipped[] = "Baris $lineNo: tidak ada item dengan QTT"; continue; }

                // PENERIMAAN KAS: BANK → Transfer, CASH → Tunai, TAGIHAN → Piutang
                $bankAmount = imp_money($get(21));
                $cash       = imp_money($get(20));
                $tagihan    = imp_money($get(22));
                $ket        = $get(23);
                if ($bankAmount > 0) {
                    $status = 'transfer';
                    $bank = imp_bank($ket);
                    if (!$bank) $warnings[] = "Baris $lineNo: BANK terisi tapi KET '$ket' tidak menyebut BSI/Muamalat";
                } elseif ($cash > 0) {
                    $status = 'tunai'; $bank = null;
                } elseif ($tagihan > 0 || stripos($ket, 'belum') !== false) {
                    $status = 'piutang'; $bank = null;
                } else {
                    $status = 'tunai'; $bank = null;
                }

                $lunasRaw = $get(2);
                $lunasDate = imp_date($lunasRaw);
                if (!$lunasDate && stripos($lunasRaw, 'lunas') !== false) {
                    $lunasDate = imp_date($get(3)); // TGL LUNAS "Lunas" → pakai TGL SETOR
                }

                $rows[] = [
                    'date'        => $date,
                    'nama'        => $nama,
                    'lunas_date'  => $lunasDate,
                    'setor_date'  => imp_date($get(3)),
                    'status'      => $status,
                    'bank'        => $bank,
                    'total'       => $total,
                    'hpp'         => $hpp,
                    'items'       => $items,
                    'cash'        => $cash,
                    'bank_amount' => $bankAmount,
                    'tagihan'     => $tagihan,
                    'ket'         => $ket,
                ];
            }
            fclose($handle);

            if ($headerIndex === null) {
                $errors[] = 'Header tidak ditemukan. Pastikan ada kolom TANGGAL dan NAMA PELANGGAN.';
            } elseif (!$rows) {
                $errors[] = 'Tidak ada baris transaksi yang bisa dibaca.';
            } else {
                $batch = strtolower(preg_replace('/[^a-z0-9]+/i', '-', pathinfo($sourceName, PATHINFO_FILENAME)));
                $_SESSION['qmas_import'] = ['batch' => $batch, 'filename' => $sourceName, 'rows' => $rows, 'skipped' => $skipped, 'warnings' => $warnings];
                $preview = $_SESSION['qmas_import'];
            }
            if ($tempFile) @unlink($tempFile);
        }
    }

    if ($action === 'batal') {
        unset($_SESSION['qmas_import']);
        flash_set('info', 'Pratinjau dibatalkan.');
        redirect('/modules/qmas/import.php');
    }

    if ($action === 'import') {
        $data = $_SESSION['qmas_import'] ?? null;
        if (!$data || empty($data['rows'])) {
            $errors[] = 'Tidak ada data pratinjau. Silakan upload ulang file CSV.';
        } else {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                run('DELETE FROM qmas_transactions WHERE import_batch = ?', [$data['batch']]);
                $stamp = date('YmdHis');
                foreach ($data['rows'] as $i => $r) {
                    $no = 'QMS-IMP-' . $stamp . '-' . str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT);
                    run('INSERT INTO qmas_transactions
                        (transaction_no,transaction_date,customer_name,customer_type,payment_status,bank,lunas_date,setor_date,total_amount,user_id,import_batch,created_at)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())', [
                        $no, $r['date'], $r['nama'], 'umum', $r['status'], $r['bank'],
                        $r['lunas_date'], $r['setor_date'], $r['total'], current_user()['id'], $data['batch'],
                    ]);
                    $tid = last_id();
                    foreach ($r['items'] as $it) {
                        run('INSERT INTO qmas_transaction_items (transaction_id,product_id,quantity,unit_price,unit_cost,subtotal) VALUES (?,?,?,?,?,?)', [
                            $tid, $it['product']['id'], $it['qty'], $it['price'], $it['cost'], $it['subtotal'],
                        ]);
                        if ($it['cost'] > 0) {
                            run('UPDATE qmas_products SET cost = ? WHERE id = ?', [$it['cost'], $it['product']['id']]);
                        }
                    }
                }
                $pdo->commit();
                activity('qmas', 'Import CSV: ' . $data['filename'] . ' (' . count($data['rows']) . ' transaksi)');
                unset($_SESSION['qmas_import']);
                flash_set('success', 'Import berhasil: ' . count($data['rows']) . ' transaksi dari ' . $data['filename'] . ' tersimpan.');
                redirect('/modules/qmas/rekap.php');
            } catch (Throwable $e) {
                $pdo->rollBack();
                $errors[] = 'Gagal mengimpor: ' . $e->getMessage();
            }
        }
    }
} else {
    $preview = $_SESSION['qmas_import'] ?? null;
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div><h1 class="section-title mb-1"><i class="bi bi-file-earmark-arrow-up" style="color:var(--qmas)"></i> Import Transaksi Qmas (CSV)</h1><div class="text-muted small">Upload hasil "Save as CSV" dari sheet list order QMAS</div></div>
    <a href="<?= base_url('/modules/qmas/rekap.php') ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-bar-chart"></i> Lihat Rekap</a>
</div>

<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<?php if ($preview):
    $statusCount = ['tunai' => 0, 'transfer' => 0, 'piutang' => 0];
    foreach ($preview['rows'] as $r) { $statusCount[$r['status']] = ($statusCount[$r['status']] ?? 0) + 1; }
?>
<div class="card content-card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span>Pratinjau: <b><?= e($preview['filename']) ?></b> · <?= count($preview['rows']) ?> transaksi</span>
        <span>
            <span class="badge bg-success">Tunai <?= $statusCount['tunai'] ?></span>
            <span class="badge bg-primary">Transfer <?= $statusCount['transfer'] ?></span>
            <span class="badge bg-warning text-dark">Piutang <?= $statusCount['piutang'] ?></span>
        </span>
    </div>
    <div class="card-body">
        <?php if ($preview['skipped']): ?>
            <div class="alert alert-warning small py-2"><b><?= count($preview['skipped']) ?> baris dilewati:</b><br><?= e(implode(' · ', array_slice($preview['skipped'], 0, 10))) ?><?= count($preview['skipped']) > 10 ? ' …' : '' ?></div>
        <?php endif; ?>
        <?php if (!empty($preview['warnings'])): ?>
            <div class="alert alert-info small py-2"><b>Perlu diperiksa (<?= count($preview['warnings']) ?>):</b><br><?= e(implode(' · ', array_slice($preview['warnings'], 0, 10))) ?><?= count($preview['warnings']) > 10 ? ' …' : '' ?></div>
        <?php endif; ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light"><tr><th>#</th><th>Tanggal</th><th>Pelanggan</th><th>Item</th><th>CASH</th><th>BANK</th><th>TAGIHAN</th><th>KET</th><th>Status</th><th class="text-end">Total</th></tr></thead>
                <tbody>
                <?php foreach (array_slice($preview['rows'], 0, 40) as $i => $r): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td class="small"><?= tgl($r['date']) ?></td>
                        <td class="small"><?= e($r['nama']) ?></td>
                        <td class="small"><?php foreach ($r['items'] as $it) echo e($it['product']['name']) . ' ×' . $it['qty'] . ' '; ?></td>
                        <td class="small"><?= $r['cash'] > 0 ? rupiah($r['cash']) : '-' ?></td>
                        <td class="small"><?= $r['bank_amount'] > 0 ? rupiah($r['bank_amount']) : '-' ?></td>
                        <td class="small"><?= $r['tagihan'] > 0 ? rupiah($r['tagihan']) : '-' ?></td>
                        <td class="small"><?= e($r['ket'] ?? '') ?></td>
                        <td><span class="badge bg-<?= payment_badge($r['status']) ?>"><?= e(payment_label($r['status'], $r['bank'])) ?></span></td>
                        <td class="text-end fw-semibold"><?= rupiah($r['total']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (count($preview['rows']) > 40): ?><div class="small text-muted mt-2">Menampilkan 40 baris pertama dari <?= count($preview['rows']) ?> baris.</div><?php endif; ?>
        <div class="d-flex gap-2 mt-3">
            <form method="post"><?php csrf_field(); ?><input type="hidden" name="action" value="import"><button class="btn btn-primary"><i class="bi bi-check2-circle"></i> Import <?= count($preview['rows']) ?> Transaksi</button></form>
            <form method="post"><?php csrf_field(); ?><input type="hidden" name="action" value="batal"><button class="btn btn-outline-secondary">Batal</button></form>
        </div>
    </div>
</div>
<?php else: ?>
<div class="row g-3">
    <div class="col-lg-7">
        <div class="card content-card">
            <div class="card-header">Ambil Data: Link Sheet atau File CSV</div>
            <div class="card-body">
                <form method="post" enctype="multipart/form-data">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="preview">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold"><i class="bi bi-link-45deg"></i> Link Google Sheets</label>
                        <input type="url" name="sheet_url" class="form-control" placeholder="https://docs.google.com/spreadsheets/d/.../edit#gid=0">
                        <div class="form-text small">Sheet harus di-share <b>"Siapa pun yang memiliki link → Viewer"</b>. Jika diisi, link yang dipakai.</div>
                    </div>
                    <div class="text-center small text-muted my-2">— atau —</div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold"><i class="bi bi-file-earmark-spreadsheet"></i> File CSV</label>
                        <input type="file" name="csv" accept=".csv" class="form-control">
                        <div class="form-text small">Hasil "Save as CSV" dari Google Sheets / Excel.</div>
                    </div>
                    <button class="btn btn-primary"><i class="bi bi-eye"></i> Pratinjau</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card content-card">
            <div class="card-header">Format Kolom (urutan tetap)</div>
            <div class="card-body small">
                <ol class="mb-2">
                    <li>TANGGAL</li><li>NAMA PELANGGAN</li><li>TGL LUNAS</li><li>TGL SETOR</li>
                    <li>GL19 QTT / PRICE</li><li>240ML QTT / PRICE</li><li>330ML QTT / PRICE</li>
                    <li>600ML QTT / PRICE</li><li>1.5L QTT / PRICE / HPP</li>
                    <li>CASH</li><li>BANK</li><li>TAGIHAN</li><li>KET</li>
                </ol>
                <div class="text-muted">Baris "BULAN …" dan baris kosong dilewati otomatis. Tanggal kosong mengikuti tanggal sebelumnya.</div>
                <a href="<?= base_url('/contoh/contoh_import_qmas.csv') ?>" class="btn btn-sm btn-outline-success mt-3" download><i class="bi bi-download"></i> Unduh Contoh CSV</a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
