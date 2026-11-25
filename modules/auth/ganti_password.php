<?php
// Halaman dipindah ke menu Pengaturan. URL lama tetap bekerja.
require_once __DIR__ . '/../../config/app.php';
require_login();
redirect('/modules/pengaturan/ganti_password.php');
