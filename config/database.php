<?php
/**
 * Koneksi database PDO – Portal BUMK UMGO
 * Default XAMPP: host localhost, user root, password kosong.
 */
define('DB_HOST', 'localhost');
define('DB_NAME', 'bumk_umgo');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

function db()
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        die('<div class="alert alert-danger m-4">Koneksi database gagal. Pastikan <b>Apache & MySQL</b> di XAMPP sudah berjalan dan database <b>bumk_umgo</b> sudah di-import via phpMyAdmin.<br><small>' . e($e->getMessage()) . '</small></div>');
    }
    return $pdo;
}
