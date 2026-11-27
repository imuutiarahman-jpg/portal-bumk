<?php
require_once __DIR__ . '/../../config/app.php';

$title = 'Panduan';
$isPublic = true;
$user = current_user();

require_once __DIR__ . '/../../includes/header.php';
?>

<section class="portal-section" id="panduan" aria-labelledby="panduanTitle">
    <div class="container-fluid px-4">
        <div class="content-card">
            <div class="card-body p-4 p-lg-5">
                <div class="section-eyebrow">Panduan Singkat</div>
                <h1 class="section-title mb-2" id="panduanTitle">Cara Mengakses Dashboard</h1>
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
                <?php else: ?>
                    <div class="alert alert-light mt-4 mb-0 small">
                        <i class="bi bi-check-circle text-success me-1"></i> Anda sudah masuk. <a href="<?= base_url(dashboard_path()) ?>">Buka dashboard Anda</a>.
                    </div>
                <?php endif; ?>
                <div class="text-center mt-4">
                    <a href="<?= base_url('/modules/portal/unit.php') ?>" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-grid-1x2"></i> Lihat Unit Usaha
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
