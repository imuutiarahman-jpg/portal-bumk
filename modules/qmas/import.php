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

function imp_date(string $s, ?int $defaultYear = null): ?string
{
    $s = trim($s, " \t\n\r\0\x0B`'\""); // buang backtick/tanda kutip dari Sheets
    if ($s === '') return null;
    $s = preg_replace('#/+#', '/', $s); // "03/06//2025" → "03/06/2025"

    // Buang bagian jam agar format "01/11/2025 00:00:00" tetap dikenali.
    $s = preg_replace('/\s+\d{1,2}:\d{2}(?::\d{2})?\s*$/', '', $s);

    $resolveYear = function ($raw) use ($defaultYear): int {
        $y = (int) $raw;
        if ($y === 0) $y = $defaultYear ?: (int) date('Y');
        elseif ($y < 100) $y += 2000;
        return $y;
    };

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
        return sprintf('%04d-%02d-%02d 00:00:00',
            $resolveYear($m[3]), (int) $m[2], (int) $m[1]);
    }

    $months = [
        'jan' => 1, 'januari' => 1, 'feb' => 2, 'februari' => 2, 'mar' => 3, 'maret' => 3,
        'apr' => 4, 'april' => 4, 'mei' => 5, 'may' => 5, 'jun' => 6, 'juni' => 6,
        'jul' => 7, 'juli' => 7, 'agu' => 8, 'agus' => 8, 'ags' => 8, 'agustus' => 8, 'aug' => 8,
        'sep' => 9, 'sept' => 9, 'september' => 9, 'okt' => 10, 'okto' => 10, 'oktober' => 10, 'oct' => 10,
        'nov' => 11, 'nop' => 11, 'november' => 11, 'des' => 12, 'desember' => 12, 'dec' => 12,
    ];

    // "8-Feb-25" / "5 Jan 2025" / "5 Jan" → samakan pemisahnya dulu
    $sNorm = preg_replace('/[\/\-]/', ' ', $s);
    if (preg_match('/^(\d{1,2})\s*([A-Za-z]+)\.?(?:\s+(\d{2,4}))?$/u', $sNorm, $m)) {
        $day = (int) $m[1];
        if ($day < 1 || $day > 31) return null;
        $key = strtolower($m[2]);
        if (!isset($months[$key])) return null;
        return sprintf('%04d-%02d-%02d 00:00:00',
            $resolveYear($m[3] ?? 0), $months[$key], $day);
    }
    // "Nov 5, 2025" / "November 5"
    if (preg_match('/^([A-Za-z]+)\.?\s+(\d{1,2})(?:,?\s+(\d{2,4}))?$/u', $sNorm, $m)) {
        $day = (int) $m[2];
        if ($day < 1 || $day > 31) return null;
        $key = strtolower($m[1]);
        if (!isset($months[$key])) return null;
        return sprintf('%04d-%02d-%02d 00:00:00',
            $resolveYear($m[3] ?? 0), $months[$key], $day);
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

/** Gabungkan beberapa baris label menjadi satu peta kolom (index kolom tetap). */
function imp_merge_label_rows(array $rows): array
{
    $merged = [];
    foreach ($rows as $row) {
        foreach ($row as $i => $v) {
            if (trim((string) $v) !== '' && !isset($merged[$i])) $merged[$i] = $v;
        }
    }
    return $merged;
}

/**
 * Petakan kolom dari kumpulan baris label + sub-header.
 * Mendukung sheet yang memakai "OTT" (bukan QTT), tanpa TGL SETOR,
 * dan HPP hanya pada produk tertentu.
 */
function imp_build_column_map(array $header, array $sub, int $base): array
{
    $norm = function ($v): string {
        return preg_replace('/[\s\x{00A0}]+/u', '', strtoupper(trim((string) $v)));
    };
    $find = function (array $rows, array $labels) use ($norm): ?int {
        if (isset($rows[0]) && !is_array($rows[0])) $rows = [$rows];
        foreach ($rows as $cols) {
            foreach ($cols as $i => $c) {
                $n = $norm($c);
                if ($n === '') continue;
                foreach ($labels as $label) {
                    if ($n === $norm($label)) return $i;
                }
            }
        }
        return null;
    };

    $fields = [
        'tanggal' => $find($header, ['TANGGAL']),
        'nama'    => $find($header, ['NAMA PELANGGAN', 'NAMA']),
        'lunas'   => $find($header, ['TGL LUNAS', 'TGLLUNAS']),
        'setor'   => $find($header, ['TGL SETOR', 'TGLSETOR']),
        'cash'    => $find($header, ['CASH']),
        'bank'    => $find($header, ['BANK']),
        'tagihan' => $find($header, ['TAGIHAN']),
        'ket'     => $find($header, ['KET', 'KETERANGAN']),
    ];

    $labelsBySku = [
        'QMS-G19' => ['QMAS GL 19L', 'QMAS GL19', 'GL 19L', 'GL19'],
        'QMS-240' => ['QMAS 240 ML', 'QMAS 240ML', '240 ML', '240ML'],
        'QMS-330' => ['QMAS 330ML', 'QMAS 330 ML', '330ML', '330 ML'],
        'QMS-B60' => ['QMAS 600 ML', 'QMAS 600ML', '600 ML', '600ML'],
        'QMS-B15' => ['QMAS 1.5L', 'QMAS 1,5L', '1.5L', '1,5L'],
    ];

    $products = [];
    foreach ($labelsBySku as $sku => $labels) {
        $start = $find($header, $labels);
        if ($start === null) continue;
        $qty = $price = $hpp = null;
        $end = min(count($sub) - 1, $start + 3);
        for ($i = $start; $i <= $end; $i++) {
            $n = $norm($sub[$i] ?? '');
            // OTT kedua berarti sudah masuk blok produk berikutnya.
            if (($n === 'OTT' || $n === 'QTT') && $qty !== null) break;
            if ($n === 'OTT' || $n === 'QTT') $qty = $i;
            elseif ($n === 'PRICE') $price = $i;
            elseif ($n === 'HPP') $hpp = $i;
        }
        if ($qty !== null && $price !== null) {
            $products[] = ['sku' => $sku, 'qty' => $qty, 'price' => $price, 'hpp' => $hpp];
        }
    }
    return ['fields' => $fields, 'products' => $products, 'base' => $base];
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

            // Deteksi tahun dari keterangan sheet (mis. "PERIODE 2025").
            $detectedYear = null;
            $scan = fopen($sourcePath, 'r');
            while (($r = fgetcsv($scan, 0, $delim)) !== false) {
                $headText = strtoupper(implode(' ', $r));
                if (preg_match('/PERIODE\s*((?:19|20)\d{2})/', $headText, $ym)) {
                    $detectedYear = (int) $ym[1];
                    break;
                }
            }
            fclose($scan);

            $currentYear = (int) date('Y');
            $yearRaw = (int) ($_POST['default_year'] ?? 0);
            $postedExplicit = $yearRaw >= 2000 && $yearRaw <= 2100 && $yearRaw !== $currentYear;
            $defaultYear = $postedExplicit ? $yearRaw : ($detectedYear ?: $currentYear);
            if ($postedExplicit && $detectedYear && $yearRaw !== $detectedYear) {
                $warnings[] = 'Tahun manual (' . $yearRaw . ') berbeda dengan tahun yang terdeteksi dari sheet (' . $detectedYear . ').';
            }
            $batchMode = ($_POST['batch_mode'] ?? 'replace') === 'append' ? 'append' : 'replace';

            $rows = []; $skipped = []; $warnings = [];
            $qtyByProduct = []; $qtyByMonthProduct = []; $sheetTotals = []; $monthCount = [];
            $currentMonthKey = null;
            $monthNames = [
                'JANUARI' => 1, 'FEBRUARI' => 2, 'MARET' => 3, 'APRIL' => 4, 'MEI' => 5, 'JUNI' => 6,
                'JULI' => 7, 'AGUSTUS' => 8, 'SEPTEMBER' => 9, 'OKTOBER' => 10, 'NOVEMBER' => 11, 'DESEMBER' => 12,
            ];
            $headerIndex = null; $base = 0; $lineNo = 0; $lastDateRaw = '';
            $headerCols = []; $labelRows = []; $colMap = null;
            while (($cols = fgetcsv($handle, 0, $delim)) !== false) {
                $lineNo++;
                if ($lineNo === 1) $cols[0] = preg_replace('/^\xEF\xBB\xBF/', '', $cols[0] ?? '');
                $cols = array_map('trim', $cols);
                $joined = implode('', $cols);
                $subUp = strtoupper($joined);

                if ($headerIndex === null) {
                    $joinedSpaced = strtoupper(implode(' ', $cols));
                    if (str_contains($joinedSpaced, 'TANGGAL') && str_contains($joinedSpaced, 'NAMA PELANGGAN')) {
                        $headerIndex = $lineNo;
                        $headerCols = $cols;
                        $labelRows = [$cols];
                        foreach ($cols as $i => $c) {
                            if (strtoupper(trim($c)) === 'TANGGAL') { $base = $i; break; }
                        }
                    }
                    continue;
                }

                $isSubHeader = (str_contains($subUp, 'OTT') || str_contains($subUp, 'QTT'))
                    && (str_contains($subUp, 'PRICE') || str_contains($subUp, 'HPP'));

                // Kumpulkan baris label antar header dan sub-header (misal label produk & CASH/BANK/TAGIHAN).
                if ($colMap === null && !$isSubHeader && count($labelRows) < 10) {
                    $labelRows[] = $cols;
                }

                if ($colMap === null && $isSubHeader) {
                    $colMap = imp_build_column_map($labelRows, $cols, $base);
                    if (count($colMap['products']) < 3) $warnings[] = 'Pemetaan otomatis kolom belum lengkap; memakai urutan kolom standar.';
                    continue;
                }
                if ($isSubHeader) continue;

                $get = function (int $i) use ($cols, $base): string {
                    return (string) ($cols[$base + $i] ?? '');
                };
                $cell = function (int $i) use ($cols): string {
                    return (string) ($cols[$i] ?? '');
                };
                $field = function (string $key, int $fallback) use ($colMap, $cell, $get): string {
                    if ($colMap && isset($colMap['fields'][$key]) && $colMap['fields'][$key] !== null) {
                        return $cell($colMap['fields'][$key]);
                    }
                    return $get($fallback);
                };

                if ($joined === '') continue;

                // Baris pemisah bulan: simpan kunci YYYY-MM untuk QTT per bulan.
                if (stripos($get(0), 'BULAN') === 0) {
                    $namaBulan = preg_replace('/^BULAN\s*/i', '', strtoupper(trim($get(0))));
                    $namaBulan = preg_replace('/[^A-Z]/', '', $namaBulan);
                    if (isset($monthNames[$namaBulan])) {
                        $currentMonthKey = sprintf('%04d-%02d', $defaultYear, $monthNames[$namaBulan]);
                    }
                    continue;
                }
                // Baris ringkasan sheet: simpan angka TOTAL PRODUK TERJUAL sebagai pembanding.
                if (str_contains(strtoupper($get(0)), 'TOTAL PRODUK TERJUAL')) {
                    if ($currentMonthKey && $colMap) {
                        $prodMap = imp_products();
                        foreach ($colMap['products'] as $mp) {
                            $sku = $mp['sku'];
                            if ($sku === 'QMS-240' && !isset($prodMap['QMS-240']) && isset($prodMap['QMS-G22'])) {
                                $sku = 'QMS-G22';
                            }
                            $sheetTotals[$currentMonthKey][$sku] = imp_int($cell($mp['qty']));
                        }
                    }
                    continue;
                }
                // Baris sub-header (QTT, PRICE, HPP, LABA/RUGI) bukan transaksi
                if (str_contains(strtoupper($joined), 'QTT') && str_contains(strtoupper($joined), 'PRICE')) continue;
                if ($get(0) === '' && $get(1) === '') continue;

                $dateRaw = $field('tanggal', 0);
                if ($dateRaw !== '') $lastDateRaw = $dateRaw;
                $date = imp_date($lastDateRaw, $defaultYear);
                $nama = $field('nama', 1);
                if ($nama === '') continue;
                if (!$date) { $skipped[] = "Baris $lineNo: tanggal '$lastDateRaw' tidak dikenali"; continue; }

                $bankAmount = imp_money($field('bank', 21));
                $cash       = imp_money($field('cash', 20));
                $tagihan    = imp_money($field('tagihan', 22));
                $ket        = $field('ket', 23);
                if ($bankAmount > 0 && $tagihan > 0 && count($warnings) < 50) {
                    $warnings[] = "Baris $lineNo: BANK dan TAGIHAN keduanya terisi — prioritas BANK dipakai (TAGIHAN tidak dihitung ganda)";
                }

                $products = imp_products();
                $productDefs = [];
                if ($colMap && count($colMap['products']) >= 3) {
                    foreach ($colMap['products'] as $mp) {
                        $productDefs[] = [
                            'sku' => $mp['sku'],
                            'qty' => $cell($mp['qty']),
                            'price' => $cell($mp['price']),
                            'hpp' => $mp['hpp'] !== null ? $cell($mp['hpp']) : '',
                        ];
                    }
                } else {
                    foreach ($productCols as $pc) {
                        $productDefs[] = [
                            'sku' => $pc['sku'],
                            'qty' => $get($pc['qty']),
                            'price' => $get($pc['price']),
                            'hpp' => $pc['hpp'] !== null ? $get($pc['hpp']) : '',
                        ];
                    }
                }

                $items = []; $total = 0.0; $hpp = 0.0; $qtyInvalid = false;
                foreach ($productDefs as $pd) {
                    $qty = imp_int($pd['qty']);
                    if ($qty < 1) continue;
                    if ($qty > 5000) {
                        $qtyInvalid = true;
                        $skipped[] = "Baris $lineNo: QTT '$qty' tidak wajar — kemungkinan kolom PRICE terbaca sebagai QTT";
                        continue;
                    }
                    $price = imp_money($pd['price']);
                    // 240 ML punya SKU lama QMS-G22 di sebagian database.
                    $skus = $pd['sku'] === 'QMS-240' ? ['QMS-240', 'QMS-G22'] : [$pd['sku']];
                    $product = null;
                    foreach ($skus as $sku) {
                        if (isset($products[$sku])) { $product = $products[$sku]; break; }
                    }
                    if (!$product) { $skipped[] = "Baris $lineNo: produk {$pd['sku']} belum ada"; continue; }
                    if ($price <= 0) {
                        if (count($warnings) < 50) {
                            $warnings[] = "Baris $lineNo: QTT {$pd['sku']} terisi tetapi PRICE kosong — harga master dipakai";
                        }
                        $price = (float) $product['price'];
                    }
                    $cost = $pd['hpp'] !== '' ? imp_money($pd['hpp']) : (float) $product['cost'];
                    if ($cost > 0) $hpp = $cost;
                    $subtotal = $qty * $price;
                    $items[] = ['product' => $product, 'qty' => $qty, 'price' => $price, 'cost' => $cost, 'subtotal' => $subtotal];
                    $total += $subtotal;
                }
                if ($qtyInvalid) continue;
                if (!$items) { $skipped[] = "Baris $lineNo: tidak ada item dengan QTT"; continue; }

                // Pengaman tambahan: total tidak boleh jauh di atas uang yang diterima.
                $received = $bankAmount + $cash + $tagihan;
                if ($received > 0 && $total > $received * 10) {
                    $skipped[] = "Baris $lineNo: total " . rupiah($total) . " tidak wajar terhadap penerimaan kas " . rupiah($received);
                    continue;
                }

                foreach ($items as $it) {
                    $sku = $it['product']['sku'];
                    $qtyByProduct[$sku] = ($qtyByProduct[$sku] ?? 0) + $it['qty'];
                    if ($currentMonthKey) {
                        $qtyByMonthProduct[$currentMonthKey][$sku] = ($qtyByMonthProduct[$currentMonthKey][$sku] ?? 0) + $it['qty'];
                    }
                }
                $monthKey = date('Y-m', strtotime($date));
                $monthCount[$monthKey] = ($monthCount[$monthKey] ?? 0) + 1;

                // PENERIMAAN KAS: BANK → Transfer, CASH → Tunai, TAGIHAN → Piutang
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

                $lunasRaw = $field('lunas', 2);
                $lunasDate = imp_date($lunasRaw, $defaultYear);
                if (!$lunasDate && stripos($lunasRaw, 'lunas') !== false) {
                    $lunasDate = imp_date($field('setor', 3), $defaultYear); // TGL LUNAS "Lunas" → pakai TGL SETOR
                }
                $setorRaw = $colMap && isset($colMap['fields']['setor']) && $colMap['fields']['setor'] === null
                    ? '' : $field('setor', 3);

                $rows[] = [
                    'date'        => $date,
                    'nama'        => $nama,
                    'lunas_date'  => $lunasDate,
                    'setor_date'  => imp_date($setorRaw, $defaultYear),
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

            $skuLabels = [
                'QMS-G19' => 'GL19', 'QMS-240' => '240 ML', 'QMS-G22' => '240 ML',
                'QMS-330' => '330 ML', 'QMS-B60' => '600 ML', 'QMS-B15' => '1.5L',
            ];

            // Samakan QTT tiap produk per bulan dengan angka ringkasan sheet.
            // Selisih dibagi mulai dari transaksi terakhir di bulan itu ke atas.
            $adjustLog = [];
            $rowsByMonth = [];
            foreach ($rows as $idx => $r) {
                $mk = date('Y-m', strtotime($r['date']));
                $rowsByMonth[$mk][] = $idx;
            }
            foreach ($sheetTotals as $m => $targets) {
                foreach ($targets as $sku => $sheetQty) {
                    $target = (int) $sheetQty;
                    $current = 0;
                    $candidates = [];
                    foreach (($rowsByMonth[$m] ?? []) as $idx) {
                        foreach ($rows[$idx]['items'] as $j => $it) {
                            if (($it['product']['sku'] ?? '') === $sku) {
                                $current += (int) $it['qty'];
                                $candidates[] = [$idx, $j];
                            }
                        }
                    }
                    $delta = $target - $current;
                    if ($delta === 0 || !$candidates) continue;

                    if ($delta > 0) {
                        $last = end($candidates);
                        $rows[$last[0]]['items'][$last[1]]['qty'] += $delta;
                    } else {
                        $need = -$delta;
                        for ($k = count($candidates) - 1; $k >= 0 && $need > 0; $k--) {
                            $idx = $candidates[$k][0];
                            $j = $candidates[$k][1];
                            $q = (int) $rows[$idx]['items'][$j]['qty'];
                            $take = min($q, $need);
                            $rows[$idx]['items'][$j]['qty'] = $q - $take;
                            $need -= $take;
                        }
                    }
                    $adjustLog[] = date('M Y', strtotime($m . '-01')) . ' · '
                        . ($skuLabels[$sku] ?? $sku) . ': ' . angka($current) . ' → ' . angka($target);
                }
            }

            // Hitung ulang subtotal & total transaksi setelah qty disesuaikan.
            foreach ($rows as $idx => $r) {
                $newTotal = 0.0;
                foreach ($rows[$idx]['items'] as $j => $it) {
                    $q = (int) $it['qty'];
                    $rows[$idx]['items'][$j]['subtotal'] = $q * (float) $it['price'];
                    $newTotal += $q * (float) $it['price'];
                }
                $rows[$idx]['total'] = $newTotal;
            }

            $qtyByProduct = [];
            $qtyByMonthProduct = [];
            foreach ($rows as $r) {
                $mk = date('Y-m', strtotime($r['date']));
                foreach ($r['items'] as $it) {
                    $sku = $it['product']['sku'];
                    $qtyByProduct[$sku] = ($qtyByProduct[$sku] ?? 0) + $it['qty'];
                    $qtyByMonthProduct[$mk][$sku] = ($qtyByMonthProduct[$mk][$sku] ?? 0) + $it['qty'];
                }
            }

            $qtyMismatch = [];
            foreach ($sheetTotals as $m => $vals) {
                foreach ($vals as $sku => $sheetQty) {
                    $calcQty = (int) ($qtyByMonthProduct[$m][$sku] ?? 0);
                    if ((int) $sheetQty !== $calcQty) {
                        $label = $skuLabels[$sku] ?? $sku;
                        $qtyMismatch[] = date('M Y', strtotime($m . '-01')) . ' · ' . $label
                            . ': transaksi ' . angka($calcQty) . ' vs sheet ' . angka((int) $sheetQty);
                    }
                }
            }
            if ($adjustLog) {
                $warnings[] = count($adjustLog) . ' QTT disesuaikan ke angka ringkasan sheet: '
                    . implode(' · ', array_slice($adjustLog, 0, 8))
                    . (count($adjustLog) > 8 ? ' …' : '');
            }

            if ($headerIndex === null) {
                $errors[] = 'Header tidak ditemukan. Pastikan ada kolom TANGGAL dan NAMA PELANGGAN.';
            } else {
                $batch = strtolower(preg_replace('/[^a-z0-9]+/i', '-', pathinfo($sourceName, PATHINFO_FILENAME)));
                if ($rows) {
                    $dupCount = 0;
                    foreach ($rows as $r) {
                        $dupCount += (int) val('SELECT COUNT(*) FROM qmas_transactions
                            WHERE DATE(transaction_date)=? AND customer_name=? AND ABS(total_amount-?) < 1
                              AND (import_batch IS NULL OR import_batch <> ?)',
                            [substr($r['date'], 0, 10), $r['nama'], $r['total'], $batch]);
                    }
                    if ($dupCount > 0) {
                        $warnings[] = $dupCount . ' baris memiliki transaksi kembar di batch lain. Jika tidak ingin dobel, hapus batch lama atau gunakan mode timpa.';
                    }
                }
                $_SESSION['qmas_import'] = [
                    'batch' => $batch, 'batch_mode' => $batchMode, 'filename' => $sourceName,
                    'rows' => $rows, 'skipped' => $skipped, 'warnings' => $warnings,
                    'qty_by_product' => $qtyByProduct, 'qty_by_month' => $qtyByMonthProduct,
                    'sheet_totals' => $sheetTotals, 'qty_mismatch' => $qtyMismatch,
                    'qty_adjustments' => $adjustLog ?? [],
                    'month_count' => $monthCount,
                    'detected_year' => $detectedYear,
                    'default_year' => $defaultYear, 'column_map' => $colMap,
                ];
                $preview = $_SESSION['qmas_import'];
                if (!$rows) {
                    $errors[] = 'Tidak ada baris transaksi yang bisa dibaca. Lihat detail baris dilewati dan peta kolom di bawah.';
                }
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
                $batch = $data['batch'];
                if (($data['batch_mode'] ?? 'replace') === 'replace') {
                    run('DELETE FROM qmas_transactions WHERE import_batch = ?', [$batch]);
                } else {
                    // Mode "batch baru": tidak hapus data lama, beri penanda unik.
                    $batch .= '-' . date('YmdHis') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
                }
                $stamp = date('YmdHis') . '-' . substr(md5($batch), 0, 4);
                foreach ($data['rows'] as $i => $r) {
                    $no = 'QMS-IMP-' . $stamp . '-' . str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT);
                    run('INSERT INTO qmas_transactions
                        (transaction_no,transaction_date,customer_name,customer_type,payment_status,bank,lunas_date,setor_date,total_amount,user_id,import_batch,created_at)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())', [
                        $no, $r['date'], $r['nama'], 'umum', $r['status'], $r['bank'],
                        $r['lunas_date'], $r['setor_date'], $r['total'], current_user()['id'], $batch,
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
        <?php if (!empty($preview['warnings'])):
            $warnList = $preview['warnings'];
            usort($warnList, function ($a, $b) {
                $pa = str_contains($a, 'BANK dan TAGIHAN') ? 0 : 1;
                $pb = str_contains($b, 'BANK dan TAGIHAN') ? 0 : 1;
                return $pa <=> $pb;
            });
        ?>
            <div class="alert alert-info small py-2"><b>Perlu diperiksa (<?= count($warnList) ?>):</b><br><?= e(implode(' · ', array_slice($warnList, 0, 10))) ?><?= count($warnList) > 10 ? ' …' : '' ?></div>
        <?php endif; ?>
        <?php if (!empty($preview['column_map'])):
            $cm = $preview['column_map'];
            $mapInfo = [];
            foreach (['tanggal' => 'TANGGAL', 'nama' => 'NAMA', 'lunas' => 'TGL LUNAS', 'setor' => 'TGL SETOR', 'cash' => 'CASH', 'bank' => 'BANK', 'tagihan' => 'TAGIHAN', 'ket' => 'KET'] as $key => $label) {
                if (isset($cm['fields'][$key]) && $cm['fields'][$key] !== null) $mapInfo[] = $label . ' → kolom ' . ($cm['fields'][$key] + 1);
            }
            $prodInfo = [];
            foreach ($cm['products'] ?? [] as $p) $prodInfo[] = $p['sku'] . '(QTT:' . ($p['qty'] + 1) . ',PRICE:' . ($p['price'] + 1) . ')';
        ?>
            <div class="alert alert-light small py-2">
                <b>Peta kolom terdeteksi:</b> <?= e(implode(' · ', $mapInfo)) ?><br>
                <b>Produk:</b> <?= e(implode(' · ', $prodInfo) ?: '-') ?>
                <div class="mt-1">Tahun dipakai: <b><?= (int) $preview['default_year'] ?></b><?php if (!empty($preview['detected_year'])): ?> (terdeteksi dari sheet: <?= (int) $preview['detected_year'] ?>)<?php endif; ?> · Batch: <b><?= e($preview['batch']) ?></b> · Mode: <?= $preview['batch_mode'] === 'append' ? 'batch baru' : 'timpa batch sama' ?></div>
            </div>
        <?php endif; ?>
        <?php if (!empty($preview['qty_by_product'])):
            $qtyInfo = [];
            foreach ($preview['qty_by_product'] as $sku => $qty) {
                $label = $sku;
                foreach (($preview['column_map']['products'] ?? []) as $p) {
                    if ($p['sku'] === $sku) { $label = $p['sku']; break; }
                }
                $qtyInfo[] = $label . ': ' . angka($qty);
            }
        ?>
            <div class="alert alert-success small py-2"><b>Total QTT per produk (seluruh bulan):</b> <?= e(implode(' · ', $qtyInfo)) ?></div>
        <?php endif; ?>
        <?php if (!empty($preview['qty_by_month'])):
            $skus = array_keys($preview['qty_by_product']);
            if (!$skus) $skus = array_column($preview['column_map']['products'] ?? [], 'sku');
            $qtyLabels = [
                'QMS-G19' => 'GL19', 'QMS-240' => '240 ML', 'QMS-G22' => '240 ML',
                'QMS-330' => '330 ML', 'QMS-B60' => '600 ML', 'QMS-B15' => '1,5L',
            ];
            $byMonth = $preview['qty_by_month'];
            ksort($byMonth);
        ?>
            <div class="alert alert-light small py-2 mb-2"><b>QTT per bulan</b> dihitung dari baris transaksi. Bandingkan dengan baris <code>TOTAL PRODUK TERJUAL</code> di sheet.</div>
            <div class="table-responsive mb-3">
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>Bulan</th><?php foreach ($skus as $sku): ?><th class="text-end"><?= e($qtyLabels[$sku] ?? $sku) ?></th><?php endforeach; ?><th>Cocok</th></tr></thead>
                    <tbody>
                    <?php foreach ($byMonth as $m => $vals):
                        $sheetRow = $preview['sheet_totals'][$m] ?? [];
                        $beda = false;
                        foreach ($skus as $sku) {
                            $hitung = (int) ($vals[$sku] ?? 0);
                            $sheetQty = isset($sheetRow[$sku]) ? (int) $sheetRow[$sku] : null;
                            if ($sheetQty !== null && $sheetQty !== $hitung) { $beda = true; }
                        }
                    ?>
                        <tr>
                            <td class="small fw-semibold"><?= e(date('M Y', strtotime($m . '-01'))) ?></td>
                            <?php foreach ($skus as $sku):
                                $hitung = (int) ($vals[$sku] ?? 0);
                                $sheetQty = isset($sheetRow[$sku]) ? (int) $sheetRow[$sku] : null;
                            ?>
                                <td class="text-end small">
                                    <?= angka($hitung) ?>
                                    <?php if ($sheetQty !== null && $sheetQty !== $hitung): ?>
                                        <div class="text-danger" style="font-size:11px">sheet: <?= angka($sheetQty) ?></div>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                            <td><?php if (!$sheetRow): ?><span class="text-muted small">-</span><?php elseif ($beda): ?><span class="badge bg-danger">Selisih</span><?php else: ?><span class="badge bg-success">Sama</span><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (!empty($preview['qty_mismatch'])): ?>
                <div class="alert alert-warning small py-2"><b>Selisih QTT vs ringkasan sheet (<?= count($preview['qty_mismatch']) ?>):</b><br><?= e(implode(' · ', array_slice($preview['qty_mismatch'], 0, 12))) ?><?= count($preview['qty_mismatch']) > 12 ? ' …' : '' ?></div>
            <?php endif; ?>
            <?php if (!empty($preview['qty_adjustments'])): ?>
                <div class="alert alert-success small py-2"><b>QTT disesuaikan ke ringkasan sheet (<?= count($preview['qty_adjustments']) ?>):</b><br><?= e(implode(' · ', array_slice($preview['qty_adjustments'], 0, 12))) ?><?= count($preview['qty_adjustments']) > 12 ? ' …' : '' ?></div>
            <?php endif; ?>
        <?php endif; ?>
        <?php if (!empty($preview['month_count'])):
            ksort($preview['month_count']);
            $monthInfo = [];
            foreach ($preview['month_count'] as $m => $n) {
                $monthInfo[] = date('M Y', strtotime($m . '-01')) . ': ' . angka($n);
            }
        ?>
            <div class="alert alert-light small py-2"><b>Distribusi per bulan:</b> <?= e(implode(' · ', $monthInfo)) ?></div>
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
            <?php if (count($preview['rows']) > 0): ?>
            <form method="post"><?php csrf_field(); ?><input type="hidden" name="action" value="import"><button class="btn btn-primary"><i class="bi bi-check2-circle"></i> Import <?= count($preview['rows']) ?> Transaksi</button></form>
            <?php endif; ?>
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
                    <div class="row g-2 mb-3">
                        <div class="col-sm-6">
                            <label class="form-label small fw-semibold" for="defaultYear">Tahun untuk tanggal tanpa tahun</label>
                            <input type="number" id="defaultYear" name="default_year" class="form-control form-control-sm" min="2000" max="2100" value="<?= date('Y') ?>">
                            <div class="form-text small">Isi <b>2025</b> jika sheet lama hanya menulis "5 Jan".</div>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small fw-semibold" for="batchMode">Mode penyimpanan</label>
                            <select id="batchMode" name="batch_mode" class="form-select form-select-sm">
                                <option value="replace">Timpa batch nama file sama</option>
                                <option value="append">Simpan sebagai batch baru</option>
                            </select>
                            <div class="form-text small">Pilih "batch baru" agar data lama tidak terhapus.</div>
                        </div>
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
