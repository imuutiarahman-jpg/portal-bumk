<?php
require_once __DIR__ . '/../../config/app.php';
require_login();

$title = 'Ganti Password';
$user = current_user();
$errors = [];
$forced = !empty($user['must_change_password']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $old  = (string) ($_POST['old_password'] ?? '');
    $new  = (string) ($_POST['new_password'] ?? '');
    $conf = (string) ($_POST['confirm_password'] ?? '');

    if (!password_verify($old, $user['password_hash'])) {
        $errors[] = 'Password lama tidak sesuai.';
    }
    $issue = password_issue($new, $user['username']);
    if ($issue) $errors[] = $issue;
    if ($new !== $conf) $errors[] = 'Konfirmasi password tidak cocok.';

    if (!$errors) {
        run('UPDATE users SET password_hash=?, must_change_password=0, last_password_changed=NOW(), failed_attempts=0, locked_until=NULL WHERE id=?',
            [password_hash($new, PASSWORD_DEFAULT), $user['id']]);
        activity('password', 'User ' . $user['username'] . ' mengganti password');
        flash_set('success', 'Password berhasil diperbarui.');
        redirect(dashboard_path());
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="row justify-content-center py-4">
    <div class="col-md-6">
        <div class="card content-card">
            <div class="card-header">
                <i class="bi bi-key"></i> Ganti Password
            </div>
            <div class="card-body">
                <?php if ($forced): ?>
                    <div class="alert alert-warning small">Untuk keamanan, Anda <b>wajib mengganti password</b> sebelum melanjutkan.</div>
                <?php endif; ?>
                <?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
                <form method="post">
                    <?php csrf_field(); ?>
                    <div class="mb-3"><label class="form-label small fw-semibold">Password Lama</label><input type="password" name="old_password" class="form-control" required autocomplete="current-password"></div>
                    <div class="mb-3"><label class="form-label small fw-semibold">Password Baru</label><input type="password" name="new_password" class="form-control" required autocomplete="new-password"></div>
                    <div class="mb-3"><label class="form-label small fw-semibold">Konfirmasi Password Baru</label><input type="password" name="confirm_password" class="form-control" required autocomplete="new-password"></div>
                    <div class="alert alert-light small mb-3">Ketentuan: minimal 8 karakter, mengandung huruf dan angka, serta tidak sama dengan username.</div>
                    <button class="btn btn-success w-100 fw-bold"><i class="bi bi-check2-circle"></i> Simpan Password</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
