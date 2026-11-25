<?php
// Halaman dipindah ke menu Pengaturan. URL lama tetap bekerja.
require_once __DIR__ . '/../../config/app.php';
require_login();
require_role(['super_admin']);
redirect('/modules/pengaturan/akun.php');
