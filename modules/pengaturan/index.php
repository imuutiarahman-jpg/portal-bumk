<?php
require_once __DIR__ . '/../../config/app.php';
require_login();

$title = 'Pengaturan';
$user = current_user();
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-head">
    <div>
        <div class="section-eyebrow">Pengaturan</div>
        <h1 class="section-title"><i class="bi bi-sliders2 text-success"></i> Pengaturan</h1>
        <div class="page-sub">Kelola akun, data, kata sandi, dan tampilan aplikasi dari satu tempat.</div>
    </div>
</div>

<div class="row g-3">
    <?php if ($user['role'] === 'super_admin'): ?>
    <div class="col-md-6 col-xl-4">
        <div class="card content-card settings-card h-100">
            <div class="card-body">
                <div class="kpi-icon bg-primary-subtle text-primary mb-3"><i class="bi bi-people"></i></div>
                <h3 class="section-title">Pengaturan Akun</h3>
                <p class="section-lead">Tambah, ubah, reset password, nonaktifkan, dan hapus akun pengguna.</p>
                <a href="<?= base_url('/modules/pengaturan/akun.php') ?>" class="btn btn-sm btn-outline-primary mt-2"><i class="bi bi-arrow-right"></i> Kelola Akun</a>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($user['role'] === 'super_admin' || ($user['unit_code'] ?? '') === 'qmas'): ?>
    <div class="col-md-6 col-xl-4">
        <div class="card content-card settings-card h-100">
            <div class="card-body">
                <div class="kpi-icon bg-danger-subtle text-danger mb-3"><i class="bi bi-trash3"></i></div>
                <h3 class="section-title">Hapus Data</h3>
                <p class="section-lead">Hapus transaksi & pembelian Qmas per bulan atau per tahun. Stok di-set 0.</p>
                <a href="<?= base_url('/modules/pengaturan/hapus_data.php') ?>" class="btn btn-sm btn-outline-danger mt-2"><i class="bi bi-arrow-right"></i> Hapus Data</a>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="col-md-6 col-xl-4">
        <div class="card content-card settings-card h-100">
            <div class="card-body">
                <div class="kpi-icon bg-warning-subtle text-warning mb-3"><i class="bi bi-key"></i></div>
                <h3 class="section-title">Ganti Password</h3>
                <p class="section-lead">Perbarui kata sandi akun Anda secara berkala.</p>
                <a href="<?= base_url('/modules/pengaturan/ganti_password.php') ?>" class="btn btn-sm btn-outline-warning mt-2"><i class="bi bi-arrow-right"></i> Ganti Password</a>
            </div>
        </div>
    </div>

    <div class="col-md-6 col-xl-4">
        <div class="card content-card settings-card h-100">
            <div class="card-body">
                <div class="kpi-icon bg-secondary-subtle text-secondary mb-3"><i class="bi bi-moon-stars"></i></div>
                <h3 class="section-title">Tampilan</h3>
                <p class="section-lead">Pilih mode terang atau gelap. Pilihan tersimpan di browser dan berlaku di semua halaman.</p>
                <div class="settings-row mt-3">
                    <div>
                        <div class="small fw-semibold">Mode saat ini</div>
                        <div class="text-muted small" id="themeLabel">Terang</div>
                    </div>
                    <button type="button" class="btn btn-outline-secondary theme-toggle" id="themeToggleSettings"><i class="bi bi-moon-stars"></i> Ganti Mode</button>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-6 col-xl-4">
        <div class="card content-card settings-card h-100">
            <div class="card-body">
                <div class="kpi-icon bg-success-subtle text-success mb-3"><i class="bi bi-box-arrow-right"></i></div>
                <h3 class="section-title">Keluar</h3>
                <p class="section-lead">Akhiri sesi Anda dan kembali ke halaman login.</p>
                <a href="<?= base_url('/modules/auth/logout.php') ?>" class="btn btn-sm btn-outline-danger mt-2"><i class="bi bi-box-arrow-right"></i> Keluar</a>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
