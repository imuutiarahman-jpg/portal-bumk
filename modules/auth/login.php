<?php
require_once __DIR__ . '/../../config/app.php';

if (is_logged_in()) {
    redirect(dashboard_path());
}

$error = '';
if (!empty($_GET['timeout'])) $error = 'Sesi Anda habis setelah 30 menit tidak aktif. Silakan login kembali.';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = clean($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    [$ok, $msg] = login_user($username, $password);

    if ($ok) {
        if (!empty(current_user()['must_change_password'])) {
            flash_set('warning', 'Silakan ganti password terlebih dahulu.');
            redirect('/modules/auth/ganti_password.php');
        }
        $next = (string) ($_POST['next'] ?? '');
        if ($next !== '' && str_starts_with($next, BASE_URL)) {
            $next = substr($next, strlen(BASE_URL));
        }
        if ($next === '' || !str_starts_with($next, '/') || str_starts_with($next, '//')) {
            $next = dashboard_path();
        }
        flash_set('success', 'Selamat datang, ' . current_user()['full_name'] . '!');
        redirect($next);
    }
    $error = $msg;
}

$next = (string) ($_GET['next'] ?? '');
$title = 'Login';
$isPublic = true;
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="login-shell">
    <div class="container">
        <div class="login-wrap">
            <div class="login-aside">
                <div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="logo-badge">UMGO</span>
                        <span class="logo-badge bumk">BUMK</span>
                    </div>
                    <h2>Satu portal untuk seluruh unit usaha kampus.</h2>
                    <p>Kelola transaksi, stok, dan laporan keuangan Qmas Air Mineral, SM Corner, serta Daqam Caffe dari satu tempat.</p>
                </div>
                <ul class="login-features">
                    <li><i class="bi bi-bar-chart-line"></i> Dashboard omzet gabungan untuk pimpinan</li>
                    <li><i class="bi bi-shield-lock"></i> Akses dibatasi sesuai peran pengguna</li>
                    <li><i class="bi bi-file-earmark-spreadsheet"></i> Laporan dan rekap dapat diekspor</li>
                </ul>
                <div class="small" style="color:rgba(255,255,255,.65)">Badan Usaha Milik Kampus · Universitas Muhammadiyah Gorontalo</div>
            </div>

            <div class="login-panel">
                <div class="section-eyebrow">Selamat datang kembali</div>
                <h1 class="section-title mb-2"><i class="bi bi-shield-lock text-success"></i> Login Sistem</h1>
                <p class="section-lead mb-4">Masuk ke Portal Unit Usaha BUMK UMGO.</p>

                <?php if ($error): ?>
                    <div class="alert alert-danger" role="alert"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= e($error) ?></div>
                <?php endif; ?>

                <form method="post">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="next" value="<?= e($next) ?>">
                    <div class="mb-3">
                        <label class="form-label" for="username">Username</label>
                        <div class="field">
                            <i class="bi bi-person field-icon" aria-hidden="true"></i>
                            <input type="text" id="username" name="username" class="form-control" placeholder="Masukkan username" required autofocus autocomplete="username" aria-describedby="usernameHelp">
                        </div>
                        <div id="usernameHelp" class="form-text">Gunakan username yang telah terdaftar.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="password">Password</label>
                        <div class="field">
                            <i class="bi bi-lock field-icon" aria-hidden="true"></i>
                            <input type="password" id="password" name="password" class="form-control" placeholder="Masukkan password" required autocomplete="current-password">
                            <button class="password-toggle" type="button" data-toggle-password="#password" aria-label="Tampilkan password"><i class="bi bi-eye"></i></button>
                        </div>
                    </div>
                    <button class="btn btn-success w-100 fw-bold mt-2" type="submit"><i class="bi bi-box-arrow-in-right"></i> Masuk</button>
                </form>

                <div class="alert alert-light mt-4 mb-0 small">
                    <i class="bi bi-info-circle text-success me-1"></i> Lupa password? Hubungi Super Admin untuk melakukan reset akun.
                </div>
                <a href="<?= base_url('/modules/portal/index.php') ?>" class="d-block text-center mt-4 small text-muted"><i class="bi bi-arrow-left"></i> Kembali ke portal utama</a>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
