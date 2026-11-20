<?php
/**
 * CONTOH KONFIGURASI UNTUK HOSTING (ROOT DOMAIN)
 * ------------------------------------------------
 * Salin isi file ini ke config/app.php di hosting, lalu sesuaikan DB.
 * Jangan dipakai di XAMPP lokal — lokal memakai BASE_URL '/portal-bumk'.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
date_default_timezone_set('Asia/Makassar');

// Root domain (https://bumkumgostore.com/) → BASE_URL kosong.
// Jangan pakai '/' karena akan menghasilkan //modules/...
define('BASE_URL', '');
define('APP_NAME', 'Portal BUMK UMGO');

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../includes/auth.php';
