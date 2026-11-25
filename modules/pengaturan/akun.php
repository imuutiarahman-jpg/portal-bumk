<?php
require_once __DIR__ . '/../../config/app.php';
require_login();
require_role(['super_admin']);

$title = 'Kelola Akun – Pengaturan';
$errors = [];
$generated = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'tambah') {
        $username = strtolower(clean($_POST['username'] ?? ''));
        $nama     = clean($_POST['full_name'] ?? '');
        $role     = $_POST['role'] ?? 'kasir';
        $unitId   = (int) ($_POST['unit_id'] ?? 0);
        $pw       = (string) ($_POST['password'] ?? '');
        if (!in_array($role, ['super_admin', 'admin_unit', 'kasir'], true)) $role = 'kasir';
        if ($role !== 'super_admin' && $unitId < 1) $errors[] = 'Unit usaha wajib dipilih untuk role Admin/Kasir.';
        if ($username === '' || $nama === '') $errors[] = 'Username dan nama wajib diisi.';
        if (!preg_match('/^[a-z0-9._-]{3,50}$/', $username)) $errors[] = 'Username hanya huruf kecil, angka, titik, garis bawah, atau dash (min. 3 karakter).';
        if (val('SELECT COUNT(*) FROM users WHERE username=?', [$username]) > 0) $errors[] = 'Username sudah digunakan.';
        if ($pw === '') {
            do { $pw = random_password(12); } while (password_issue($pw, $username));
            $generated = $pw;
        } else {
            $issue = password_issue($pw, $username);
            if ($issue) $errors[] = $issue;
        }
        if (!$errors) {
            try {
                run('INSERT INTO users (unit_id,username,password_hash,full_name,role,is_active,must_change_password,created_at) VALUES (?,?,?,?,?,1,1,NOW())',
                    [$role === 'super_admin' ? null : $unitId, $username, password_hash($pw, PASSWORD_DEFAULT), $nama, $role]);
                activity('akun', 'Super Admin membuat akun ' . $username . ' (' . $role . ')');
                flash_set('success', 'Akun ' . $username . ' berhasil dibuat. Password: <b>' . e($pw) . '</b>');
                if ($generated) flash_set('warning', 'Simpan password generate ini sekarang: <b>' . e($generated) . '</b>');
            } catch (Throwable $e) { $errors[] = 'Gagal membuat akun: ' . $e->getMessage(); }
        }
    }

    if ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $nama = clean($_POST['full_name'] ?? '');
        $role = $_POST['role'] ?? 'kasir';
        $unitId = (int) ($_POST['unit_id'] ?? 0);
        if (!in_array($role, ['super_admin', 'admin_unit', 'kasir'], true)) $role = 'kasir';
        if ($nama === '') $errors[] = 'Nama wajib diisi.';
        if ($role !== 'super_admin' && $unitId < 1) $errors[] = 'Unit usaha wajib dipilih.';
        if ($id === (int) current_user()['id'] && $role !== 'super_admin') $errors[] = 'Role akun sendiri tidak bisa diturunkan.';
        if (!$errors) {
            run('UPDATE users SET full_name=?, role=?, unit_id=? WHERE id=?', [$nama, $role, $role === 'super_admin' ? null : $unitId, $id]);
            activity('akun', 'Super Admin mengubah akun #' . $id);
            flash_set('success', 'Data akun diperbarui.');
        }
    }

    if ($action === 'reset') {
        $id = (int) ($_POST['id'] ?? 0);
        $u = row('SELECT * FROM users WHERE id=?', [$id]);
        $pw = (string) ($_POST['new_password'] ?? '');
        if (!$u) $errors[] = 'Akun tidak ditemukan.';
        if ($pw === '') {
            do { $pw = random_password(12); } while (password_issue($pw, $u['username'] ?? ''));
            $generated = $pw;
        } else {
            $issue = password_issue($pw, $u['username'] ?? '');
            if ($issue) $errors[] = $issue;
        }
        if (!$errors) {
            run('UPDATE users SET password_hash=?, must_change_password=1, failed_attempts=0, locked_until=NULL, last_password_changed=NOW() WHERE id=?',
                [password_hash($pw, PASSWORD_DEFAULT), $id]);
            activity('akun', 'Super Admin reset password akun ' . ($u['username'] ?? '#' . $id));
            flash_set('success', 'Password akun ' . ($u['username'] ?? '') . ' direset.');
            if ($generated) flash_set('warning', 'Password baru: <b>' . e($generated) . '</b>');
        }
    }

    if ($action === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id === (int) current_user()['id']) {
            $errors[] = 'Tidak bisa menonaktifkan akun sendiri.';
        } else {
            run('UPDATE users SET is_active = CASE WHEN is_active=1 THEN 0 ELSE 1 END WHERE id=?', [$id]);
            activity('akun', 'Super Admin mengubah status aktif akun #' . $id);
            flash_set('success', 'Status akun diperbarui.');
        }
    }

    if ($action === 'hapus') {
        $id = (int) ($_POST['id'] ?? 0);
        $u = row('SELECT * FROM users WHERE id=?', [$id]);
        if (!$u) $errors[] = 'Akun tidak ditemukan.';
        if ($id === (int) current_user()['id']) $errors[] = 'Tidak bisa menghapus akun sendiri.';
        if ($u && $u['role'] === 'super_admin' && (int) val('SELECT COUNT(*) FROM users WHERE role="super_admin" AND is_active=1') <= 1) {
            $errors[] = 'Tidak bisa menghapus Super Admin terakhir.';
        }
        if (!$errors) {
            try {
                run('DELETE FROM users WHERE id=?', [$id]);
                activity('akun', 'Super Admin menghapus akun ' . $u['username']);
                flash_set('success', 'Akun ' . $u['username'] . ' dihapus.');
            } catch (Throwable $e) { $errors[] = 'Gagal menghapus akun: ' . $e->getMessage(); }
        }
    }

    foreach ($errors as $err) flash_set('danger', $err);
    redirect('/modules/pengaturan/akun.php');
}

$users = all('SELECT u.*, un.name unit_name FROM users u LEFT JOIN units un ON un.id=u.unit_id ORDER BY FIELD(u.role,"super_admin","admin_unit","kasir"), u.id');
$units = all('SELECT * FROM units ORDER BY id');
$logs = all('SELECT l.*, u.username FROM activity_logs l LEFT JOIN users u ON u.id=l.user_id ORDER BY l.created_at DESC LIMIT 40');
$superCount = (int) val('SELECT COUNT(*) FROM users WHERE role="super_admin" AND is_active=1');
$userCount  = count($users);
$activeCount = count(array_filter($users, fn ($u) => (int) $u['is_active'] === 1));
$lockedCount = count(array_filter($users, fn ($u) => !empty($u['locked_until']) && strtotime($u['locked_until']) > time()));

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-head">
    <div>
        <div class="section-eyebrow">Administrasi</div>
        <h1 class="section-title"><i class="bi bi-people-fill text-success"></i> Kelola Akun Pengguna</h1>
        <div class="page-sub">Buat dan kelola akun Admin/Kasir untuk masing-masing unit usaha.</div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card kpi-card p-3"><div class="d-flex justify-content-between"><span class="kpi-label">TOTAL AKUN</span><span class="kpi-icon bg-primary-subtle text-primary"><i class="bi bi-people"></i></span></div><div class="kpi-value"><?= $userCount ?></div><div class="kpi-foot">Seluruh akun terdaftar</div></div></div>
    <div class="col-md-3"><div class="card kpi-card p-3"><div class="d-flex justify-content-between"><span class="kpi-label">AKUN AKTIF</span><span class="kpi-icon bg-success-subtle text-success"><i class="bi bi-check2-circle"></i></span></div><div class="kpi-value text-success"><?= $activeCount ?></div><div class="kpi-foot">Dapat digunakan</div></div></div>
    <div class="col-md-3"><div class="card kpi-card p-3"><div class="d-flex justify-content-between"><span class="kpi-label">SUPER ADMIN</span><span class="kpi-icon bg-warning-subtle text-warning"><i class="bi bi-shield-check"></i></span></div><div class="kpi-value"><?= $superCount ?></div><div class="kpi-foot">Akses seluruh unit</div></div></div>
    <div class="col-md-3"><div class="card kpi-card p-3"><div class="d-flex justify-content-between"><span class="kpi-label">AKUN TERKUNCI</span><span class="kpi-icon bg-danger-subtle text-danger"><i class="bi bi-lock"></i></span></div><div class="kpi-value text-danger"><?= $lockedCount ?></div><div class="kpi-foot">Login gagal berulang</div></div></div>
</div>

<div class="row g-3">
    <div class="col-xl-8">
        <div class="card content-card">
            <div class="card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
                <span><i class="bi bi-list-ul me-1"></i> Daftar Akun</span>
                <span class="badge bg-light text-dark border"><?= $userCount ?> akun</span>
            </div>
            <div class="table-responsive"><table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light"><tr><th>Pengguna</th><th>Role</th><th>Unit</th><th>Status</th><th>Keamanan</th><th>Aksi</th></tr></thead>
                <tbody>
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <span class="user-avatar sm"><?= e(strtoupper(mb_substr($u['full_name'] ?? $u['username'], 0, 2))) ?></span>
                                <div>
                                    <div class="small fw-semibold"><?= e($u['full_name']) ?></div>
                                    <div class="text-muted" style="font-size:11.5px">@<?= e($u['username']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td class="small"><?= e(role_label($u['role'])) ?></td>
                        <td class="small"><?= e($u['unit_name'] ?? '-') ?></td>
                        <td>
                            <span class="badge bg-<?= $u['is_active'] ? 'success' : 'secondary' ?> status-badge"><?= $u['is_active'] ? 'Aktif' : 'Nonaktif' ?></span>
                            <?php if (!empty($u['locked_until']) && strtotime($u['locked_until']) > time()): ?><br><span class="badge bg-danger mt-1">Terkunci</span><?php endif; ?>
                        </td>
                        <td class="small">
                            <?php if ($u['must_change_password']): ?><span class="badge bg-warning text-dark">Wajib ganti</span><?php else: ?><span class="badge bg-light text-dark">OK</span><?php endif; ?>
                            <?php if ($u['failed_attempts'] > 0): ?><br><span class="text-danger">Gagal: <?= (int) $u['failed_attempts'] ?></span><?php endif; ?>
                        </td>
                        <td>
                            <div class="action-group">
                                <button class="btn btn-sm btn-outline-warning btn-edit" data-id="<?= $u['id'] ?>" data-name="<?= e($u['full_name']) ?>" data-role="<?= e($u['role']) ?>" data-unit="<?= (int) $u['unit_id'] ?>">Ubah</button>
                                <button class="btn btn-sm btn-outline-primary btn-reset" data-id="<?= $u['id'] ?>" data-name="<?= e($u['username']) ?>">Reset</button>
                                <?php if ((int) $u['id'] !== (int) current_user()['id']): ?>
                                <form method="post" class="d-inline" onsubmit="return confirm('Ubah status akun ini?')"><?php csrf_field(); ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $u['id'] ?>"><button class="btn btn-sm btn-outline-secondary"><?= $u['is_active'] ? 'Nonaktif' : 'Aktifkan' ?></button></form>
                                <form method="post" class="d-inline" onsubmit="return confirm('Hapus akun ini permanen?')"><?php csrf_field(); ?><input type="hidden" name="action" value="hapus"><input type="hidden" name="id" value="<?= $u['id'] ?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> Hapus</button></form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="card content-card mb-3">
            <div class="card-header"><i class="bi bi-person-plus me-1"></i> Tambah Akun Baru</div>
            <div class="card-body">
                <form method="post">
                    <?php csrf_field(); ?><input type="hidden" name="action" value="tambah">
                    <div class="mb-2"><label class="form-label" for="newUsername">Username</label><input id="newUsername" name="username" class="form-control form-control-sm" required></div>
                    <div class="mb-2"><label class="form-label" for="newName">Nama Lengkap</label><input id="newName" name="full_name" class="form-control form-control-sm" required></div>
                    <div class="row g-2 mb-2"><div class="col-6"><label class="form-label" for="newRole">Role</label><select id="newRole" name="role" class="form-select form-select-sm"><option value="kasir">Kasir</option><option value="admin_unit">Admin Unit</option><option value="super_admin">Super Admin</option></select></div><div class="col-6"><label class="form-label" for="newUnit">Unit</label><select id="newUnit" name="unit_id" class="form-select form-select-sm"><option value="">-- Pilih --</option><?php foreach ($units as $un): ?><option value="<?= $un['id'] ?>"><?= e($un['name']) ?></option><?php endforeach; ?></select></div></div>
                    <div class="mb-3"><label class="form-label" for="newPassword">Password (kosongkan = generate otomatis)</label><input type="text" id="newPassword" name="password" class="form-control form-control-sm" placeholder="Min. 8 char, huruf + angka"></div>
                    <button class="btn btn-sm w-100 text-white fw-bold" style="background:var(--umgo-800)"><i class="bi bi-plus-lg"></i> Buat Akun</button>
                </form>
            </div>
        </div>
        <div class="card content-card"><div class="card-header"><i class="bi bi-clock-history me-1"></i> Riwayat Aktivitas Login</div><div class="table-responsive"><table class="table table-sm mb-0"><thead class="table-light"><tr><th>Waktu</th><th>User</th><th>Aktivitas</th></tr></thead><tbody>
            <?php if (!$logs): ?><tr><td colspan="3" class="empty-state">Belum ada aktivitas.</td></tr><?php endif; ?>
            <?php foreach ($logs as $l): ?><tr><td class="small"><?= tgl($l['created_at'], true) ?></td><td class="small"><?= e($l['username'] ?? '-') ?></td><td class="small"><?= e($l['action']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div>
    </div>
</div>

<!-- Modal Ubah -->
<div class="modal fade" id="editModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <form method="post"><?php csrf_field(); ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" id="editId">
        <div class="modal-header"><h5 class="modal-title">Ubah Akun</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="mb-2"><label class="form-label small fw-semibold">Nama</label><input name="full_name" id="editName" class="form-control form-control-sm" required></div>
            <div class="row g-2"><div class="col-6"><label class="form-label small fw-semibold">Role</label><select name="role" id="editRole" class="form-select form-select-sm"><option value="kasir">Kasir</option><option value="admin_unit">Admin Unit</option><option value="super_admin">Super Admin</option></select></div><div class="col-6"><label class="form-label small fw-semibold">Unit</label><select name="unit_id" id="editUnit" class="form-select form-select-sm"><option value="">-- Pilih --</option><?php foreach ($units as $un): ?><option value="<?= $un['id'] ?>"><?= e($un['name']) ?></option><?php endforeach; ?></select></div></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Batal</button><button class="btn btn-sm btn-success">Simpan</button></div>
    </form>
</div></div></div>

<!-- Modal Reset -->
<div class="modal fade" id="resetModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <form method="post"><?php csrf_field(); ?><input type="hidden" name="action" value="reset"><input type="hidden" name="id" id="resetId">
        <div class="modal-header"><h5 class="modal-title">Reset Password</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><div class="alert alert-light py-2 small"><i class="bi bi-info-circle me-1"></i> User <b id="resetName"></b> akan diminta ganti password saat login berikutnya.</div><div class="mb-2"><label class="form-label" for="resetPassword">Password Baru (kosongkan = generate)</label><input type="text" id="resetPassword" name="new_password" class="form-control form-control-sm"><div class="strength" data-strength-for="resetPassword" aria-hidden="true"><span></span><span></span><span></span></div></div></div>
        <div class="modal-footer"><button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Batal</button><button class="btn btn-sm btn-primary">Reset</button></div>
    </form>
</div></div></div>

<script>
document.addEventListener('DOMContentLoaded', function () {
if (typeof bootstrap === 'undefined') return;
var em = new bootstrap.Modal(document.getElementById('editModal'));
document.querySelectorAll('.btn-edit').forEach(function (b) { b.addEventListener('click', function () {
    document.getElementById('editId').value = b.dataset.id;
    document.getElementById('editName').value = b.dataset.name;
    document.getElementById('editRole').value = b.dataset.role;
    document.getElementById('editUnit').value = b.dataset.unit;
    em.show();
}); });
var rm = new bootstrap.Modal(document.getElementById('resetModal'));
document.querySelectorAll('.btn-reset').forEach(function (b) { b.addEventListener('click', function () {
    document.getElementById('resetId').value = b.dataset.id;
    document.getElementById('resetName').textContent = b.dataset.name;
    rm.show();
}); });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
