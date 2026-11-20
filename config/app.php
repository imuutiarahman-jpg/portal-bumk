<?php
/**
 * Bootstrap aplikasi. Di-include oleh semua halaman.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Makassar');

define('BASE_URL', '/portal-bumk');
define('APP_NAME', 'Portal BUMK UMGO');

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../includes/auth.php';
