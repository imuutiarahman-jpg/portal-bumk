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

function sheet_export_url(string $url): array
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
        if (!empty($parts[$key]) && preg_match('/(?:^|&)gid=(\d+)/', $parts[$key], $g)) { $gid = $g[1]; break; }
    }
    return [
        'https://docs.google.com/spreadsheets/d/' . $id . '/export?format=csv&gid=' . $gid,
        'sheet-' . $id . '-gid' . $gid,
        '',
    ];
}

function sheet_fetch_csv(string $exportUrl): array
{
    if (!function_exists('curl_init')) return [null, 'cURL tidak tersedia di server XAMPP.'];
    $ch = curl_init($exportUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 20,
        CURLOPT_USERAGENT => 'Portal-BUMK-UMGO-Import/1.0',
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $content = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
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
