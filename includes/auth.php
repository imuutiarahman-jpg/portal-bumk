<?php
/** Autentikasi & Role-Based Access Control. */

function current_user(): ?array
{
    static $user = null;
    if ($user !== null) {
        return $user;
    }
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $user = row('SELECT u.*, un.code AS unit_code, un.name AS unit_name, un.color AS unit_color
                 FROM users u LEFT JOIN units un ON un.id = u.unit_id
                 WHERE u.id = ? AND u.is_active = 1', [$_SESSION['user_id']]);
    if (!$user) {
        session_destroy();
        $user = null;
    }
    return $user;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function require_login(): void
{
    if (!is_logged_in()) {
        $next = urlencode($_SERVER['REQUEST_URI'] ?? '');
        redirect('/modules/auth/login.php?next=' . $next);
    }
    if (isset($_SESSION['last_activity']) && time() - (int) $_SESSION['last_activity'] > 1800) {
        logout_user();
        redirect('/modules/auth/login.php?timeout=1');
    }
    $_SESSION['last_activity'] = time();
}

/** Paksa ganti password bila akun masih berflag. */
function require_password_change(): void
{
    $user = current_user();
    if (!$user || empty($user['must_change_password'])) return;
    if (str_contains((string) ($_SERVER['PHP_SELF'] ?? ''), 'ganti_password.php')) return;
    redirect('/modules/pengaturan/ganti_password.php');
}

function require_role(array $roles): void
{
    $user = current_user();
    if (!$user) {
        require_login();
    }
    if (!in_array($user['role'], $roles, true)) {
        http_response_code(403);
        die('<div class="alert alert-danger m-4">Akses ditolak. Hak akses Anda tidak mencukupi untuk halaman ini. <a href="' . base_url('/modules/portal/index.php') . '">Kembali ke portal</a></div>');
    }
}

/** Super admin boleh semua unit; admin/kasir hanya unitnya sendiri. */
function require_unit(string $unitCode): void
{
    $user = current_user();
    if (!$user) {
        require_login();
    }
    if ($user['role'] === 'super_admin') {
        return;
    }
    if ($user['unit_code'] !== $unitCode) {
        http_response_code(403);
        die('<div class="alert alert-danger m-4">Akses ditolak. Akun ini hanya memiliki akses ke unit <b>' . e((string) $user['unit_name']) . '</b>. <a href="' . base_url('/modules/portal/index.php') . '">Kembali ke portal</a></div>');
    }
}

function login_user(string $username, string $password): array
{
    $user = row('SELECT * FROM users WHERE username = ? AND is_active = 1', [$username]);

    if ($user && !empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
        return [false, 'Akun terkunci karena terlalu banyak gagal login. Coba lagi setelah ' . tgl($user['locked_until'], true) . '.'];
    }

    if (!$user || !password_verify($password, $user['password_hash'])) {
        if ($user) {
            $attempts = (int) $user['failed_attempts'] + 1;
            $lock = $attempts >= 5 ? date('Y-m-d H:i:s', time() + 15 * 60) : null;
            run('UPDATE users SET failed_attempts=?, locked_until=? WHERE id=?', [$attempts, $lock, $user['id']]);
            activity('login_gagal', 'Login gagal untuk ' . $user['username'] . ' (percobaan ke-' . $attempts . ')');
            if ($lock) return [false, 'Akun dikunci 15 menit karena 5 kali login gagal.'];
        }
        return [false, 'Username atau password salah.'];
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['last_activity'] = time();
    run('UPDATE users SET last_login=NOW(), failed_attempts=0, locked_until=NULL WHERE id=?', [$user['id']]);
    activity('login', 'Login berhasil: ' . $user['username']);
    return [true, ''];
}

function logout_user(): void
{
    activity('logout', 'Logout');
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function dashboard_path(): string
{
    $user = current_user();
    if (!$user) {
        return '/modules/auth/login.php';
    }
    if ($user['role'] === 'super_admin') {
        return '/modules/superadmin/dashboard.php';
    }
    $map = [
        'qmas'     => '/modules/qmas/dashboard.php',
        'smcorner' => '/modules/smcorner/dashboard.php',
        'daqam'    => '/modules/daqam/dashboard.php',
    ];
    return $map[$user['unit_code']] ?? '/modules/portal/index.php';
}

function role_label(string $role): string
{
    return [
        'super_admin' => 'Pimpinan / Super Admin',
        'admin_unit'  => 'Admin Unit',
        'kasir'       => 'Kasir',
    ][$role] ?? $role;
}
