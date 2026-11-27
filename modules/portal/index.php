<?php
require_once __DIR__ . '/../../config/app.php';

$title = 'Portal Utama';
$isPublic = true;
$user = current_user();
require_once __DIR__ . '/../../includes/portal_units.php';

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
            <a href="<?= base_url('/modules/portal/unit.php') ?>" class="btn btn-outline-light"><i class="bi bi-arrow-right-circle"></i> Lihat Unit Usaha</a>
        </div>
        <div class="hero-metrics" aria-label="Ringkasan portal">
            <div class="hero-metric"><i class="bi bi-grid-1x2-fill"></i><div><b>3 Unit Usaha</b><span>Qmas · SM Corner · Daqam</span></div></div>
            <div class="hero-metric"><i class="bi bi-link-45deg"></i><div><b>Terintegrasi</b><span>Satu portal, data per unit</span></div></div>
            <div class="hero-metric"><i class="bi bi-shield-check"></i><div><b>Akses Sesuai Peran</b><span>Super Admin & admin unit</span></div></div>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
