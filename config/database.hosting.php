<?php
/**
 * CONTOH KONFIGURASI DATABASE HOSTING
 * -----------------------------------
 * Salin isi file ini ke config/database.php di hosting.
 * Nilai di bawah harus disamakan dengan panel hosting (cPanel/Plesk).
 */
define('DB_HOST', 'localhost');            // coba '127.0.0.1' kalau localhost gagal
define('DB_NAME', 'bumb9491_bumk');        // nama database di panel
define('DB_USER', 'bumb9491_bumk');        // user database di panel
define('DB_PASS', 'PASSWORD_DARI_PANEL');  // password persis (tanpa spasi tambahan)
define('DB_CHARSET', 'utf8mb4');

function db()
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        die('<div class="alert alert-danger m-4">Koneksi database gagal.<br><small>' . htmlspecialchars($e->getMessage()) . '</small></div>');
    }
    return $pdo;
}
