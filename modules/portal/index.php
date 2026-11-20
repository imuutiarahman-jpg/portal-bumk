<?php
require_once __DIR__ . '/../../config/app.php';

$title = 'Portal Utama';
$isPublic = true;
$user = current_user();

$units = [
    [
        'code'  => 'qmas',
        'icon'  => 'bi-droplet-fill',
        'items' => ['Distribusi & Stok Air Mineral', 'Penjualan Galon, Gelas, Botol', 'Piutang & Tukar Galon Kosong'],
        'cta'   => 'Masuk Dashboard Qmas',
    ],
    [
        'code'  => 'smcorner',
        'icon'  => 'bi-bag-check-fill',
        'items' => ['Kain, Baju Kemeja & Batik', 'Perlengkapan Hizbul Wathan (HW)', 'Kasir POS & Cetak Struk'],
        'cta'   => 'Masuk Dashboard SM Corner',
    ],
    [
        'code'  => 'daqam',
        'icon'  => 'bi-cup-hot-fill',
        'items' => ['Kasir POS Makanan & Minuman', 'Manajemen Menu & Bahan Baku', 'Laporan Shift Kasir'],
        'cta'   => 'Masuk Dashboard Daqam',
    ],
];

$linkFor = function (string $code) use ($user): string {
    $dashboard = '/modules/' . $code . '/dashboard.php';
    if ($user && ($user['role'] === 'super_admin' || $user['unit_code'] === $code)) {
        return base_url($dashboard);
    }
    return base_url('/modules/auth/login.php?next=' . urlencode($dashboard));
};

require_once __DIR__ . '/../../includes/header.php';
?>

<section class="hero-banner" id="hero">
    <div class="container-fluid px-4 position-relative" style="z-index:1">
        <div class="small text-uppercase fw-bold" style="letter-spacing:2px;opacity:.8">Badan Usaha Milik Kampus · UMGO</div>
        <h1 class="mt-3">"Sinergi Kemandirian Ekonomi Kampus Universitas Muhammadiyah Gorontalo"</h1>
        <p class="mb-0">Pilih Unit Usaha di Bawah Ini untuk Masuk ke Dashboard Operasional</p>
        <?php if ($user): ?>
            <a href="<?= base_url(dashboard_path()) ?>" class="btn btn-light btn-sm mt-3 fw-semibold"><i class="bi bi-speedometer2"></i> Buka Dashboard Saya</a>
        <?php endif; ?>
    </div>
</section>

<section id="panduan" class="container-fluid px-4 py-5">
    <div class="text-center mb-4">
        <h2 class="section-title">Menu Unit Usaha</h2>
        <p class="text-muted mb-0">Satu pintu masuk untuk seluruh lini bisnis BUMK UMGO</p>
    </div>

    <div class="unit-grid">
        <?php foreach ($units as $u):
            $unit = unit_by_code($u['code']);
            $color = $unit['color'] ?? '#0B4F3E';
        ?>
        <div class="unit-card">
            <div class="card-accent" style="background:<?= e($color) ?>"></div>
            <div class="card-body p-4 text-center">
                <div class="card-icon mx-auto mb-3" style="background:<?= e($color) ?>"><i class="bi <?= e($u['icon']) ?>"></i></div>
                <h3 class="card-title"><?= e(strtoupper($unit['name'] ?? $u['code'])) ?></h3>
                <p class="card-text mt-2 mb-3"><?= e($unit['description'] ?? '') ?></p>
                <ul class="text-start">
                    <?php foreach ($u['items'] as $item): ?>
                        <li><i class="bi bi-check-circle-fill" style="color:<?= e($color) ?>"></i><?= e($item) ?></li>
                    <?php endforeach; ?>
                </ul>
                <a href="<?= e($linkFor($u['code'])) ?>" class="btn btn-unit text-white" style="background:<?= e($color) ?>">
                    <?= e($u['cta']) ?> <i class="bi bi-arrow-right"></i>
                </a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</section>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
