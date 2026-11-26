<?php
/** Helper umum: escaping, format, query, flash, CSRF, redirect. */

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function rupiah($value): string
{
    return 'Rp ' . number_format((float) $value, 0, ',', '.');
}

function angka($value): string
{
    return number_format((float) $value, 0, ',', '.');
}

function tgl($value, bool $withTime = false): string
{
    if (!$value) {
        return '-';
    }
    return date('d-m-Y' . ($withTime ? ' H:i' : ''), strtotime($value));
}

function clean($value): string
{
    return trim(strip_tags((string) $value));
}

function redirect(string $path): void
{
    header('Location: ' . BASE_URL . $path);
    exit;
}

function base_url(string $path = ''): string
{
    return BASE_URL . $path;
}

/* ---------- Query helpers ---------- */

function all(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function row(string $sql, array $params = []): ?array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $result = $stmt->fetch();
    return $result === false ? null : $result;
}

function val(string $sql, array $params = [])
{
    $r = row($sql, $params);
    return $r ? reset($r) : null;
}

function run(string $sql, array $params = []): bool
{
    return db()->prepare($sql)->execute($params);
}

function last_id(): int
{
    return (int) db()->lastInsertId();
}

/**
 * Hitung ulang stok Qmas dari stok awal + pembelian - penjualan (termasuk hasil import).
 * Hanya pergerakan tanggal > opening_stock_date yang dihitung (jika tanggal stok awal diisi),
 * dan dibatasi hingga hari ini (<= NOW()). Dengan begitu pembelian backdated hanya
 * memengaruhi transaksi setelah tanggalnya, dan data sebelum stok awal tidak dikurangi dua kali.
 */
function qmas_recalc_stock(): void
{
    run('UPDATE `qmas_products` p
         SET `stock` = GREATEST(0,
             p.`opening_stock`
             + COALESCE((
                 SELECT SUM(i.`quantity`) FROM `qmas_purchase_items` i
                 JOIN `qmas_purchases` pu ON pu.`id` = i.`purchase_id`
                 WHERE i.`product_id` = p.`id`
                   AND pu.`purchase_date` <= NOW()
                   AND (p.`opening_stock_date` IS NULL OR pu.`purchase_date` > p.`opening_stock_date`)
             ), 0)
             - COALESCE((
                 SELECT SUM(i.`quantity`) FROM `qmas_transaction_items` i
                 JOIN `qmas_transactions` t ON t.`id` = i.`transaction_id`
                 WHERE i.`product_id` = p.`id`
                   AND t.`transaction_date` <= NOW()
                   AND (p.`opening_stock_date` IS NULL OR t.`transaction_date` > p.`opening_stock_date`)
             ), 0)
         )');
}

function unit_by_code(string $code): ?array
{
    static $cache = [];
    if (!isset($cache[$code])) {
        $cache[$code] = row('SELECT * FROM units WHERE code = ?', [$code]);
    }
    return $cache[$code];
}

/* ---------- Flash message ---------- */

function flash_set(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $message];
}

function flash_render(): string
{
    if (empty($_SESSION['flash'])) {
        return '';
    }
    $html = '';
    foreach ($_SESSION['flash'] as $f) {
        $html .= '<div class="alert alert-' . e($f['type']) . ' alert-dismissible fade show" role="alert">'
            . e($f['msg'])
            . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    }
    unset($_SESSION['flash']);
    return $html;
}

/* ---------- CSRF ---------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): void
{
    echo '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}

function csrf_check(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return;
    }
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        die('Token CSRF tidak valid. Silakan muat ulang halaman.');
    }
}

/* ---------- Logging ---------- */

function activity(string $action, string $description): void
{
    $user = current_user();
    try {
        run('INSERT INTO activity_logs (user_id, action, description, ip_address) VALUES (?,?,?,?)', [
            $user['id'] ?? null,
            $action,
            $description,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $ignored) {
        // jangan mengganggu alur utama jika log gagal
    }
}

/* ---------- Nomor transaksi ---------- */

function transaction_no(string $prefix, string $table, string $column): string
{
    $date = date('Ymd');
    $last = val("SELECT COUNT(*) FROM $table WHERE DATE(created_at) = CURDATE()");
    return $prefix . '-' . $date . '-' . str_pad((int) $last + 1, 4, '0', STR_PAD_LEFT);
}

function option_selected($current, $value): string
{
    return (string) $current === (string) $value ? ' selected' : '';
}

/* ---------- Status pembayaran Qmas ---------- */

function payment_label(string $status, ?string $bank = null): string
{
    return match ($status) {
        'tunai'    => 'Tunai',
        'transfer' => 'Transfer' . ($bank ? ' (' . $bank . ')' : ''),
        'piutang'  => 'Piutang',
        'lunas'    => 'Lunas', // fallback data lama sebelum migrasi
        default    => ucfirst($status),
    };
}

function payment_badge(string $status): string
{
    return match ($status) {
        'tunai', 'lunas' => 'success',
        'transfer'       => 'primary',
        'piutang'        => 'warning',
        default          => 'secondary',
    };
}

function payment_bank(string $status, $postedBank = null): ?string
{
    if ($status !== 'transfer') {
        return null;
    }
    $bank = strtoupper(clean((string) $postedBank));
    return in_array($bank, ['BSI', 'MUAMALAT'], true) ? $bank : null;
}

/* ---------- Klasifikasi pembayaran Qmas untuk laporan ---------- */

/**
 * Tanggal mulai transaksi Tunai dihitung sebagai Tunai di laporan.
 * Transaksi berstatus Tunai sebelum tanggal ini diklasifikasikan sebagai Transfer.
 */
define('LAPORAN_TUNAI_SEJAK', '2026-11-24');

function qmas_laporan_status(string $status, $date = null): string
{
    if ($status === 'tunai' && $date !== null
        && substr((string) $date, 0, 10) < LAPORAN_TUNAI_SEJAK) {
        return 'transfer';
    }
    return $status;
}

/** Ekspresi SQL yang setara dengan qmas_laporan_status(), untuk query GROUP BY. */
function qmas_laporan_status_sql(string $statusCol = 'payment_status', string $dateCol = 'transaction_date'): string
{
    return "CASE WHEN $statusCol='tunai' AND DATE($dateCol) < '" . LAPORAN_TUNAI_SEJAK
        . "' THEN 'transfer' ELSE $statusCol END";
}

/* ---------- Kebijakan password ---------- */

function password_issue(string $password, string $username = ''): ?string
{
    if (strlen($password) < 8) return 'Password minimal 8 karakter.';
    if (!preg_match('/[A-Za-z]/', $password)) return 'Password harus mengandung huruf.';
    if (!preg_match('/\d/', $password)) return 'Password harus mengandung angka.';
    if ($username !== '' && strtolower($password) === strtolower($username)) {
        return 'Password tidak boleh sama dengan username.';
    }
    return null;
}

function random_password(int $length = 12): string
{
    $chars = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < $length; $i++) $out .= $chars[random_int(0, strlen($chars) - 1)];
    return $out;
}

/* ---------- Google Sheets helper (dipakai modul import) ---------- */

/** Validasi link Google Sheets → [id, gid, error]. */
function sheet_link_info(string $url): array
{
    $url = trim($url);
    if ($url === '') return [null, '0', ''];
    $parts = parse_url($url);
    if (!$parts || strtolower($parts['scheme'] ?? '') !== 'https') return [null, '0', 'Link harus diawali https://'];
    if (!in_array(strtolower($parts['host'] ?? ''), ['docs.google.com', 'www.docs.google.com'], true)) {
        return [null, '0', 'Hanya link Google Sheets (docs.google.com) yang didukung.'];
    }
    if (!preg_match('#/spreadsheets/d/([A-Za-z0-9_-]+)#', $parts['path'] ?? '', $m)) {
        return [null, '0', 'ID sheet tidak ditemukan dalam link.'];
    }
    $gid = '0';
    foreach (['query', 'fragment'] as $key) {
        if (!empty($parts[$key]) && preg_match('/(?:^|&)gid=(\d+)/', $parts[$key], $g)) { $gid = $g[1]; break; }
    }
    return [$m[1], $gid, ''];
}

/** Ambil konten URL via cURL. Return [content, error]. */
function sheet_fetch_content(string $url, int $timeout = 20): array
{
    if (!function_exists('curl_init')) return [null, 'cURL tidak tersedia di server XAMPP.'];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'Portal-BUMK-UMGO-Import/1.0',
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $content = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($content === false) return [null, 'Gagal mengambil sheet: ' . $err];
    if ($code !== 200) return [null, 'Google mengembalikan HTTP ' . $code . '.'];
    if (trim($content) === '') return [null, 'Sheet kosong.'];
    $head = ltrim(substr($content, 0, 600));
    if (stripos($head, '<!DOCTYPE html') === 0 || stripos($head, '<html') === 0 || stripos($head, '<?xml') === 0) {
        return [null, 'Sheet tidak bisa diakses. Pastikan Sharing = "Siapa pun yang memiliki link" (Viewer).'];
    }
    return [$content, ''];
}

function sheet_export_url(string $url): array
{
    [$id, $gid, $error] = sheet_link_info($url);
    if ($error !== '') return [null, '', $error];
    if (!$id) return [null, '', ''];
    return [
        'https://docs.google.com/spreadsheets/d/' . $id . '/export?format=csv&gid=' . $gid,
        'sheet-' . $id . '-gid' . $gid,
        '',
    ];
}

function sheet_fetch_csv(string $exportUrl): array
{
    [$content, $error] = sheet_fetch_content($exportUrl);
    if ($error !== '') return [null, $error];
    if (strlen($content) > 10 * 1024 * 1024) return [null, 'CSV terlalu besar (maksimal 10 MB).'];
    return [$content, ''];
}

/* ---------- Google Sheets XLSX helper (import seluruh tab) ---------- */

/** Ubah link edit Google Sheets menjadi URL ekspor XLSX (semua tab). */
function sheet_xlsx_url(string $url): array
{
    [$id, , $error] = sheet_link_info($url);
    if ($error !== '') return [null, '', $error];
    if (!$id) return [null, '', ''];
    return ['https://docs.google.com/spreadsheets/d/' . $id . '/export?format=xlsx', $id, ''];
}

/** Unduh XLSX ke file sementara. Return [path, error]. */
function sheet_fetch_xlsx(string $exportUrl): array
{
    [$content, $error] = sheet_fetch_content($exportUrl, 30);
    if ($error !== '') return [null, $error];
    if (strlen($content) > 20 * 1024 * 1024) return [null, 'File XLSX terlalu besar (maksimal 20 MB).'];
    if (strncmp($content, 'PK', 2) !== 0) {
        return [null, 'File yang diunduh bukan XLSX. Pastikan Sharing = "Siapa pun yang memiliki link" (Viewer).'];
    }
    $tmp = tempnam(sys_get_temp_dir(), 'sm_full_');
    if ($tmp === false || file_put_contents($tmp, $content) === false) return [null, 'Gagal menyimpan file sementara.'];
    return [$tmp, ''];
}

/** Ambil semua teks dari elemen <is>/<si>, termasuk rich text <r><t>. */
function sheet_xlsx_text(SimpleXMLElement $node, string $nsUri): string
{
    $text = '';
    foreach ($node->children($nsUri) as $child) {
        if ($child->getName() === 't') $text .= (string) $child;
        elseif ($child->getName() === 'r') $text .= sheet_xlsx_text($child, $nsUri);
    }
    return $text;
}

/**
 * Baca seluruh tab dari file XLSX.
 * Return: [ ['name' => ..., 'rows' => [ ['A' => val, 'B' => val], ... ]], ... ]
 */
function sheet_read_xlsx(string $path): array
{
    if (!class_exists('ZipArchive')) return [];
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return [];

    $nsUri = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $shared = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        $ss = simplexml_load_string($ssXml);
        if ($ss !== false) {
            foreach ($ss->si as $si) {
                $shared[] = sheet_xlsx_text($si, $nsUri);
            }
        }
    }

    $wbXml = $zip->getFromName('xl/workbook.xml');
    $relXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($wbXml === false || $relXml === false) { $zip->close(); return []; }
    $wb = simplexml_load_string($wbXml);
    $rels = simplexml_load_string($relXml);
    if ($wb === false || $rels === false) { $zip->close(); return []; }

    $targets = [];
    foreach ($rels->Relationship as $rel) {
        $targets[(string) $rel['Id']] = ltrim((string) $rel['Target'], '/');
    }

    $out = [];
    foreach ($wb->sheets->sheet as $sheet) {
        $rAttrs = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $rid = (string) ($rAttrs['id'] ?? '');
        $target = $targets[$rid] ?? '';
        if ($target === '') continue;
        if (strpos($target, 'xl/') !== 0) $target = 'xl/' . $target;
        $xml = $zip->getFromName($target);
        if ($xml === false) continue;
        $sx = simplexml_load_string($xml);
        if ($sx === false) continue;

        $rows = [];
        foreach ($sx->sheetData->row as $r) {
            $cells = [];
            foreach ($r->c as $c) {
                $col = preg_replace('/\d+/', '', (string) $c['r']);
                $type = (string) $c['t'];
                $hasV = isset($c->v);
                if ($type === 's' && $hasV) {
                    $val = $shared[(int) $c->v] ?? '';
                } elseif (isset($c->is)) {
                    $val = sheet_xlsx_text($c->is, $nsUri);
                } elseif ($hasV && (string) $c->v !== '') {
                    $val = (string) $c->v; // sel rumus tanpa cache tetap diambil apa adanya
                } else {
                    $val = '';
                }
                $cells[$col] = $val;
            }
            $rows[] = $cells;
        }
        $out[] = ['name' => (string) $sheet['name'], 'rows' => $rows];
    }
    $zip->close();
    return $out;
}

/* ---------- Chart helper ---------- */

function chart(string $type, array $labels, array $datasets, string $class = ''): string
{
    $prepared = [];
    foreach ($datasets as $d) {
        $prepared[] = [
            'label' => $d['label'] ?? 'Data',
            'data'  => array_values($d['data'] ?? []),
            'color' => $d['color'] ?? '#0B4F3E',
            'colors' => $d['colors'] ?? [],
            'fill'  => (bool) ($d['fill'] ?? true),
        ];
    }
    $attrs = 'data-chart="' . e($type) . '"'
        . ' data-labels="' . e(json_encode($labels, JSON_UNESCAPED_UNICODE)) . '"'
        . ' data-datasets="' . e(json_encode($prepared, JSON_UNESCAPED_UNICODE)) . '"'
        . ' class="' . e($class) . '"';
    return '<canvas ' . $attrs . '></canvas>';
}
