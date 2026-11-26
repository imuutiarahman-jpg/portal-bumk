<?php
/**
 * Halaman diagnostik hosting. Buka /health.php, lalu HAPUS file ini setelah selesai.
 */
header('Content-Type: text/html; charset=utf-8');
$rows = [];
function hrow(string $item, bool $ok, string $info = ''): void
{
    global $rows;
    $rows[] = [$item, $ok ? 'OK' : 'GAGAL', $info];
}

hrow('Versi PHP', version_compare(PHP_VERSION, '8.0.0', '>='), PHP_VERSION);
foreach (['pdo_mysql', 'curl', 'mbstring', 'openssl'] as $ext) {
    hrow('Ekstensi ' . $ext, extension_loaded($ext));
}
$appCode = @file_get_contents(__DIR__ . '/config/app.php');
$baseUrl = null;
if ($appCode && preg_match("/define\s*\(\s*'BASE_URL'\s*,\s*([^)]+)\)/", $appCode, $m)) {
    $baseUrl = trim($m[1], " \t\n\r\0\x0B'\"");
}
hrow('BASE_URL', $baseUrl !== null, $baseUrl === null ? 'tidak terdefinisi' : var_export($baseUrl, true));

$pdo = null;
try {
    require_once __DIR__ . '/config/database.php';
    $pdo = db();
    hrow('Koneksi database', true, DB_HOST . '/' . DB_NAME);
} catch (Throwable $e) {
    hrow('Koneksi database', false, $e->getMessage());
}

if ($pdo) {
    $tables = [
        'units', 'users', 'activity_logs',
        'qmas_products', 'qmas_transactions', 'qmas_transaction_items', 'qmas_gallon_exchanges',
        'qmas_purchases', 'qmas_purchase_items',
        'sm_categories', 'sm_products', 'sm_transactions', 'sm_transaction_items',
        'sm_purchases', 'sm_purchase_items', 'sm_catalog_summary',
        'daqam_categories', 'daqam_menu', 'daqam_ingredients', 'daqam_recipes',
        'daqam_orders', 'daqam_order_items', 'daqam_shift_closings',
    ];
    foreach ($tables as $t) {
        $n = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
        $n->execute([DB_NAME, $t]);
        hrow('Tabel ' . $t, (bool) $n->fetchColumn());
    }
    $cols = [
        'qmas_products' => ['cost', 'is_active', 'opening_stock', 'opening_stock_date'],
        'qmas_transactions' => ['bank', 'lunas_date', 'setor_date', 'import_batch'],
        'qmas_purchases' => ['lunas_date'],
        'qmas_transaction_items' => ['unit_cost'],
        'sm_products' => ['cost'],
        'sm_transaction_items' => ['unit_cost'],
    ];
    foreach ($cols as $table => $names) {
        foreach ($names as $col) {
            $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
            $q->execute([DB_NAME, $table, $col]);
            hrow('Kolom ' . $table . '.' . $col, (bool) $q->fetchColumn());
        }
    }
}

hrow('Session writable', session_status() === PHP_SESSION_ACTIVE || (bool) ini_get('session.save_path'));
hrow('upload_max_filesize', true, ini_get('upload_max_filesize'));
hrow('post_max_size', true, ini_get('post_max_size'));
?>
<!doctype html><html lang="id"><head><meta charset="utf-8"><title>Health Check – Portal BUMK</title>
<style>body{font-family:system-ui,Arial,sans-serif;background:#F4F7F9;color:#0F172A;padding:24px}table{border-collapse:collapse;width:100%;background:#fff;border-radius:12px;overflow:hidden}th,td{padding:9px 12px;border-bottom:1px solid #E8EDF2;text-align:left;font-size:13px}th{background:#F8FAFC} .ok{color:#059669;font-weight:700}.bad{color:#DC2626;font-weight:700}h1{font-size:22px}</style></head><body>
<h1>Diagnostik Hosting – Portal BUMK UMGO</h1>
<p style="color:#64748B;font-size:13px">Buka halaman ini, cek hasilnya, lalu <b>hapus file health.php</b> dari hosting.</p>
<table><thead><tr><th>Pemeriksaan</th><th>Status</th><th>Keterangan</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><?= htmlspecialchars($r[0]) ?></td><td class="<?= $r[1] === 'OK' ? 'ok' : 'bad' ?>"><?= $r[1] ?></td><td><?= htmlspecialchars($r[2]) ?></td></tr><?php endforeach; ?>
</tbody></table></body></html>
