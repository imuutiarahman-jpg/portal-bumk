<?php
require_once __DIR__ . '/../../config/app.php';

if (is_logged_in()) {
    redirect(dashboard_path());
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = clean($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    if (login_user($username, $password)) {
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
    $error = 'Username atau password salah.';
}

$next = (string) ($_GET['next'] ?? '');
$title = 'Login';
$isPublic = true;
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-5">
            <div class="card content-card">
                <div class="card-header bg-white py-3">
                    <div class="section-title"><i class="bi bi-shield-lock text-success"></i> Login Sistem</div>
                    <div class="small text-muted">Masuk ke Portal Unit Usaha BUMK UMGO</div>
                </div>
                <div class="card-body p-4">
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?= e($error) ?></div>
                    <?php endif; ?>
                    <form method="post">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="next" value="<?= e($next) ?>">
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Username</label>
                            <input type="text" name="username" class="form-control" placeholder="mis. superadmin" required autofocus autocomplete="username">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Password</label>
                            <input type="password" name="password" class="form-control" placeholder="••••••••" required autocomplete="current-password">
                        </div>
                        <button class="btn btn-success w-100 fw-bold" type="submit"><i class="bi bi-box-arrow-in-right"></i> Masuk</button>
                    </form>

                    <div class="mt-4 p-3 bg-light rounded">
                        <div class="small fw-bold mb-2">Akun demo (password: <code>password123</code>)</div>
                        <table class="table table-sm table-borderless small mb-0">
                            <tr><td><code>superadmin</code></td><td>Pimpinan – lihat omzet gabungan</td></tr>
                            <tr><td><code>admin_qmas</code> / <code>kasir_qmas</code></td><td>Qmas Air Mineral</td></tr>
                            <tr><td><code>admin_smcorner</code></td><td>Suara Muhammadiyah Corner</td></tr>
                            <tr><td><code>kasir_daqam</code></td><td>Daqam Caffe</td></tr>
                        </table>
                    </div>
                    <a href="<?= base_url('/modules/portal/index.php') ?>" class="d-block text-center mt-3 small">← Kembali ke portal utama</a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
