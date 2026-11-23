<?php
/** Header + sidebar global. Variabel: $title, $isPublic (bool), $activeUnit (string|null) */
$isPublic   = $isPublic ?? false;
$activeUnit = $activeUnit ?? null;
$user       = current_user();
if ($user) require_password_change();
$currentFile = basename($_SERVER['PHP_SELF']);

$unitMenus = [
    'qmas' => [
        'dashboard.php'  => 'Dashboard',
        'transaksi.php'  => 'Transaksi & Distribusi',
        'stok.php'       => 'Stok & Galon',
        'pembelian.php'  => 'Pembelian / Stok Masuk',
        'laporan.php'    => 'Laporan Keuangan',
        'rekap.php'      => 'Rekap Bulanan',
        'import.php'     => 'Import CSV',
    ],
    'smcorner' => [
        'dashboard.php' => 'Dashboard',
        'produk.php'    => 'Katalog & Stok',
        'pembelian.php' => 'Pembelian / Stok Masuk',
        'kasir.php'     => 'Kasir POS',
        'laporan.php'       => 'Laporan',
        'rekap.php'         => 'Rekap Bulanan',
        'import_katalog.php'=> 'Import Katalog Sheet',
    ],
    'daqam' => [
        'dashboard.php'  => 'Dashboard',
        'kasir.php'      => 'Kasir POS',
        'menu.php'       => 'Menu & Resep',
        'bahanbaku.php'  => 'Bahan Baku',
        'shift.php'      => 'Laporan Shift',
        'laporan.php'    => 'Laporan Penjualan',
    ],
];
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? APP_NAME) ?> · <?= APP_NAME ?></title>
    <link href="<?= base_url('/assets/vendor/bootstrap/bootstrap.min.css') ?>?v=1" rel="stylesheet" onerror="this.href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css'">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= base_url('/assets/css/style.css') ?>?v=2" rel="stylesheet">
    <script src="<?= base_url('/assets/vendor/bootstrap/bootstrap.bundle.min.js') ?>?v=2"></script>
    <script>window.bootstrap || document.write('<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"><\/script>');</script>
    <script src="<?= base_url('/assets/vendor/chartjs/chart.umd.min.js') ?>?v=1"></script>
</head>
<body class="<?= $isPublic ? 'public-page' : 'app-page' ?>">

<header class="portal-header">
    <div class="container-fluid px-4 h-100 d-flex align-items-center justify-content-between">
        <?php if (!$isPublic && $user): ?>
            <button class="btn mobile-menu-btn" type="button" id="menuToggle" aria-label="Buka menu" aria-expanded="false" aria-controls="appSidebar"><i class="bi bi-list"></i></button>
        <?php endif; ?>
        <a href="<?= base_url('/modules/portal/index.php') ?>" class="d-flex align-items-center gap-2 text-decoration-none text-dark">
            <span class="logo-badge">UMGO</span>
            <span class="logo-badge bumk">BUMK</span>
            <span class="fw-bold ms-1 d-none d-lg-inline portal-title">Portal Sistem Informasi Unit Usaha BUMK UMGO</span>
        </a>
        <nav class="d-flex align-items-center gap-2">
            <a class="btn btn-sm btn-outline-success" href="<?= base_url('/modules/portal/index.php') ?>"><i class="bi bi-house-door"></i> Beranda</a>
            <?php if ($user): ?>
                <?php if ($user['role'] === 'super_admin'): ?>
                    <a class="btn btn-sm btn-outline-secondary d-none d-md-inline-block" href="<?= base_url('/modules/superadmin/dashboard.php') ?>"><i class="bi bi-bar-chart-line"></i> Omzet Gabungan</a>
                <?php else: ?>
                    <a class="btn btn-sm btn-outline-secondary d-none d-md-inline-block" href="<?= base_url(dashboard_path()) ?>"><i class="bi bi-speedometer2"></i> Dashboard Saya</a>
                <?php endif; ?>
                <span class="badge bg-light text-dark border d-none d-sm-inline-block"><?= e(role_label($user['role'])) ?></span>
                <span class="user-pill d-none d-lg-inline-block"><i class="bi bi-person-circle"></i> <?= e($user['full_name']) ?></span>
                <a class="btn btn-sm btn-outline-danger" href="<?= base_url('/modules/auth/logout.php') ?>"><i class="bi bi-box-arrow-right"></i> Keluar</a>
            <?php else: ?>
                <a class="btn btn-sm btn-success" href="<?= base_url('/modules/auth/login.php') ?>"><i class="bi bi-shield-lock"></i> Login Pimpinan</a>
            <?php endif; ?>
        </nav>
    </div>
</header>

<?php if (!$isPublic && $user): ?>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<div class="container-fluid app-layout">
    <div class="row">
        <aside class="col-md-2 col-lg-2 app-sidebar p-3" id="appSidebar">
            <div class="d-flex justify-content-between align-items-center">
                <div class="sidebar-label mb-0">MENU UTAMA</div>
                <button class="btn btn-sm sidebar-close d-lg-none" type="button" id="menuClose" aria-label="Tutup menu"><i class="bi bi-x-lg"></i></button>
            </div>
            <a href="<?= base_url('/modules/portal/index.php') ?>" class="sidebar-link <?= $currentFile === 'index.php' && !$activeUnit ? 'active' : '' ?>"><i class="bi bi-grid-1x2"></i> Portal Utama</a>

            <?php if ($user['role'] === 'super_admin'): ?>
                <div class="sidebar-label mt-3">PIMPINAN</div>
                <a href="<?= base_url('/modules/superadmin/dashboard.php') ?>" class="sidebar-link <?= str_contains($_SERVER['PHP_SELF'], 'superadmin') ? 'active' : '' ?>"><i class="bi bi-bar-chart-line"></i> Omzet Gabungan</a>
                <div class="sidebar-label mt-3">UNIT USAHA</div>
                <?php foreach (['qmas', 'smcorner', 'daqam'] as $code): $u = unit_by_code($code); ?>
                    <div class="sidebar-unit"><?= e($u['name'] ?? $code) ?></div>
                    <?php foreach ($unitMenus[$code] as $file => $label): ?>
                        <a href="<?= base_url('/modules/' . $code . '/' . $file) ?>" class="sidebar-link sub <?= $activeUnit === $code && $currentFile === $file ? 'active' : '' ?>"><?= e($label) ?></a>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            <?php elseif (isset($unitMenus[$user['unit_code']])):
                $code = $user['unit_code']; $u = unit_by_code($code); ?>
                <div class="sidebar-label mt-3"><?= e(strtoupper($u['name'] ?? $code)) ?></div>
                <?php foreach ($unitMenus[$code] as $file => $label): ?>
                    <a href="<?= base_url('/modules/' . $code . '/' . $file) ?>" class="sidebar-link <?= $activeUnit === $code && $currentFile === $file ? 'active' : '' ?>"><i class="bi bi-chevron-right small"></i> <?= e($label) ?></a>
                <?php endforeach; ?>
            <?php endif; ?>

            <div class="sidebar-label mt-4">AKUN</div>
            <?php if ($user['role'] === 'super_admin'): ?>
                <a href="<?= base_url('/modules/superadmin/users.php') ?>" class="sidebar-link <?= $currentFile === 'users.php' ? 'active' : '' ?>"><i class="bi bi-people"></i> Kelola Akun</a>
            <?php endif; ?>
            <a href="<?= base_url('/modules/auth/ganti_password.php') ?>" class="sidebar-link <?= $currentFile === 'ganti_password.php' ? 'active' : '' ?>"><i class="bi bi-key"></i> Ganti Password</a>
            <a href="<?= base_url('/modules/auth/logout.php') ?>" class="sidebar-link text-danger"><i class="bi bi-box-arrow-left"></i> Keluar</a>
        </aside>
        <main class="col-md-10 col-lg-10 p-4 app-main">
            <?= flash_render() ?>
<?php else: ?>
        <main class="portal-main">
            <?= flash_render() ?>
<?php endif; ?>
