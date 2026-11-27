<?php
/**
 * Data unit usaha untuk halaman portal publik.
 * Dipakai bersama oleh index.php (badge hero), unit.php, dan panduan.php.
 */
$units = [
    [
        'code'  => 'qmas',
        'short' => 'Qmas',
        'icon'  => 'bi-droplet-fill',
        'items' => ['Distribusi & Stok Air Mineral', 'Penjualan Galon, Gelas, Botol', 'Piutang & Tukar Galon Kosong'],
        'cta'   => 'Masuk Dashboard Qmas',
    ],
    [
        'code'  => 'smcorner',
        'short' => 'SM Corner',
        'icon'  => 'bi-bag-check-fill',
        'items' => ['Kain, Baju Kemeja & Batik', 'Perlengkapan Hizbul Wathan (HW)', 'Kasir POS & Cetak Struk'],
        'cta'   => 'Masuk Dashboard SM Corner',
    ],
    [
        'code'  => 'daqam',
        'short' => 'Daqam',
        'icon'  => 'bi-cup-hot-fill',
        'items' => ['Kasir POS Makanan & Minuman', 'Manajemen Menu & Bahan Baku', 'Laporan Shift Kasir'],
        'cta'   => 'Masuk Dashboard Daqam',
    ],
];

$user = $user ?? current_user();
$linkFor = static function (string $code) use ($user): string {
    $dashboard = '/modules/' . $code . '/dashboard.php';
    if ($user && ($user['role'] === 'super_admin' || $user['unit_code'] === $code)) {
        return base_url($dashboard);
    }
    return base_url('/modules/auth/login.php?next=' . urlencode($dashboard));
};
