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

<section class="hero-split" id="hero" aria-labelledby="heroTitle">
    <div class="hero-visual with-illustration">
        <div class="visual-glow" aria-hidden="true"></div>
        <div class="visual-orbit" aria-hidden="true"><i class="bi bi-diagram-3"></i></div>
        <img src="<?= base_url('/gambar/' . rawurlencode('Friendly Small Business E-Commerce Scene.png')) ?>"
             alt="Ilustrasi pelaku usaha BUMK UMGO dalam aktivitas penjualan" class="hero-illustration">
        <div class="visual-logos">
            <img src="<?= base_url('/logo/images.png') ?>" alt="Lambang UMGO" class="visual-logo umgo">
            <span class="visual-logo-wrap"><img src="<?= base_url('/logo/logobumk.jpeg') ?>" alt="Logo BUMK UMGO" class="visual-logo bumk"></span>
        </div>
        <?php foreach ($units as $u):
            $unit = unit_by_code($u['code']); ?>
            <span class="hero-badge b<?= $u['code'] === 'qmas' ? '1' : ($u['code'] === 'smcorner' ? '2' : '3') ?>"><i class="bi <?= e($u['icon']) ?>"></i> <?= e($unit['name'] ?? $u['code']) ?></span>
        <?php endforeach; ?>
    </div>
    <div class="hero-panel">
        <div class="hero-kicker"><i class="bi bi-mortarboard-fill"></i> Badan Usaha Milik Kampus · UMGO</div>
        <h1 id="heroTitle">"Sinergi Kemandirian Ekonomi Kampus Universitas Muhammadiyah Gorontalo"</h1>
        <p>Pilih unit usaha di bawah ini untuk masuk ke dashboard operasional. Satu pintu masuk untuk seluruh lini bisnis BUMK UMGO.</p>
        <div class="hero-actions">
            <?php if ($user): ?>
                <a href="<?= base_url(dashboard_path()) ?>" class="btn btn-light"><i class="bi bi-speedometer2"></i> Buka Dashboard Saya</a>
            <?php else: ?>
                <a href="<?= base_url('/modules/auth/login.php') ?>" class="btn btn-light"><i class="bi bi-shield-lock"></i> Login Sistem</a>
            <?php endif; ?>
            <a href="#unit-usaha" class="btn btn-outline-light"><i class="bi bi-arrow-down-circle"></i> Lihat Unit Usaha</a>
        </div>
        <div class="hero-metrics" aria-label="Ringkasan portal">
            <div class="hero-metric"><i class="bi bi-grid-1x2-fill"></i><div><b>3 Unit Usaha</b><span>Qmas · SM Corner · Daqam</span></div></div>
            <div class="hero-metric"><i class="bi bi-link-45deg"></i><div><b>Terintegrasi</b><span>Satu portal, data per unit</span></div></div>
            <div class="hero-metric"><i class="bi bi-shield-check"></i><div><b>Akses Sesuai Peran</b><span>Super Admin & admin unit</span></div></div>
        </div>
    </div>
</section>

<section id="unit-usaha" class="container-fluid px-4 py-5" aria-labelledby="unitTitle">
    <div class="text-center mb-5">
        <div class="section-eyebrow">Menu Unit Usaha</div>
        <h2 class="section-title" id="unitTitle">Pilih Unit Usaha Anda</h2>
        <p class="section-lead mb-0">Setiap unit memiliki dashboard, transaksi, stok, dan laporan tersendiri.</p>
    </div>

    <div class="unit-grid">
        <?php foreach ($units as $u):
            $unit = unit_by_code($u['code']);
        ?>
        <article class="unit-card" aria-labelledby="card-<?= e($u['code']) ?>">
            <div class="card-accent"></div>
            <div class="card-body">
                <div class="card-icon mx-auto"><i class="bi <?= e($u['icon']) ?>" aria-hidden="true"></i></div>
                <h3 class="card-title text-center" id="card-<?= e($u['code']) ?>"><?= e(strtoupper($unit['name'] ?? $u['code'])) ?></h3>
                <p class="card-text text-center"><?= e($unit['description'] ?? '') ?></p>
                <ul>
                    <?php foreach ($u['items'] as $item): ?>
                        <li><i class="bi bi-check-circle-fill" aria-hidden="true"></i><?= e($item) ?></li>
                    <?php endforeach; ?>
                </ul>
                <a href="<?= e($linkFor($u['code'])) ?>" class="btn btn-unit btn-brand text-white">
                    <?= e($u['cta']) ?> <i class="bi bi-arrow-right" aria-hidden="true"></i>
                </a>
            </div>
        </article>
        <?php endforeach; ?>
    </div>
</section>

<section id="panduan" class="container-fluid px-4 pb-5" aria-labelledby="panduanTitle">
    <div class="content-card">
        <div class="card-body p-4 p-lg-5">
            <div class="section-eyebrow">Panduan Singkat</div>
            <h2 class="section-title mb-2" id="panduanTitle">Cara Mengakses Dashboard</h2>
            <p class="section-lead mb-4">Akses disesuaikan dengan peran akun Anda. Super Admin dapat melihat seluruh unit.</p>
            <div class="steps-grid">
                <div class="step-card">
                    <div class="step-no">1</div>
                    <h3><i class="bi bi-box-arrow-in-right me-1"></i> Masuk ke Sistem</h3>
                    <p>Gunakan akun yang telah diberikan. Kasir/Admin masuk lewat kartu unit masing-masing.</p>
                </div>
                <div class="step-card">
                    <div class="step-no">2</div>
                    <h3><i class="bi bi-person-badge me-1"></i> Sesuaikan Hak Akses</h3>
                    <p>Super Admin melihat omzet gabungan; Admin/Kasir hanya melihat dashboard unitnya.</p>
                </div>
                <div class="step-card">
                    <div class="step-no">3</div>
                    <h3><i class="bi bi-graph-up-arrow me-1"></i> Kelola & Pantau</h3>
                    <p>Catat transaksi, pantau stok, dan unduh laporan keuangan langsung dari dashboard.</p>
                </div>
            </div>
            <?php if (!$user): ?>
                <div class="alert alert-light mt-4 mb-0 d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <span class="small"><i class="bi bi-info-circle text-success me-1"></i> Belum memiliki akun? Hubungi pengelola BUMK UMGO.</span>
                    <a href="<?= base_url('/modules/auth/login.php') ?>" class="btn btn-sm btn-success"><i class="bi bi-shield-lock"></i> Masuk Sekarang</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
