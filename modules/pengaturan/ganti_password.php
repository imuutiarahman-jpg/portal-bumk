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
    <div class="col-md-7 col-lg-6">
        <div class="page-head">
            <div>
                <div class="section-eyebrow">Keamanan Akun</div>
                <h1 class="section-title"><i class="bi bi-key text-success"></i> Ganti Password</h1>
                <div class="page-sub">Perbarui kata sandi secara berkala untuk menjaga keamanan akun Anda.</div>
            </div>
        </div>

        <?php if ($forced): ?>
            <div class="alert alert-warning" role="alert"><i class="bi bi-exclamation-triangle-fill me-2"></i> Untuk keamanan, Anda <b>wajib mengganti password</b> sebelum melanjutkan.</div>
        <?php endif; ?>
        <?php if ($errors): ?>
            <div class="alert alert-danger" role="alert"><i class="bi bi-x-octagon-fill me-2"></i><ul class="mb-0 ps-3"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>

        <div class="card content-card">
            <div class="card-body p-4">
                <form method="post">
                    <?php csrf_field(); ?>
                    <div class="mb-3">
                        <label class="form-label" for="old_password">Password Lama</label>
                        <div class="field">
                            <i class="bi bi-lock field-icon" aria-hidden="true"></i>
                            <input type="password" id="old_password" name="old_password" class="form-control" required autocomplete="current-password">
                            <button class="password-toggle" type="button" data-toggle-password="#old_password" aria-label="Tampilkan password"><i class="bi bi-eye"></i></button>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="new_password">Password Baru</label>
                        <div class="field">
                            <i class="bi bi-shield-lock field-icon" aria-hidden="true"></i>
                            <input type="password" id="new_password" name="new_password" class="form-control" required autocomplete="new-password">
                            <button class="password-toggle" type="button" data-toggle-password="#new_password" aria-label="Tampilkan password"><i class="bi bi-eye"></i></button>
                        </div>
                        <div class="strength" data-strength-for="new_password" aria-hidden="true"><span></span><span></span><span></span></div>
                        <div class="strength-note">Minimal 8 karakter, mengandung huruf dan angka, serta tidak sama dengan username.</div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label" for="confirm_password">Konfirmasi Password Baru</label>
                        <div class="field">
                            <i class="bi bi-shield-check field-icon" aria-hidden="true"></i>
                            <input type="password" id="confirm_password" name="confirm_password" class="form-control" required autocomplete="new-password">
                            <button class="password-toggle" type="button" data-toggle-password="#confirm_password" aria-label="Tampilkan password"><i class="bi bi-eye"></i></button>
                        </div>
                    </div>
                    <button class="btn btn-success w-100 fw-bold"><i class="bi bi-check2-circle"></i> Simpan Password</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
