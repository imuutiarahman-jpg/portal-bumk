<?php
require_once __DIR__ . '/../../config/app.php';
logout_user();
flash_set('success', 'Anda telah keluar dari sistem.');
redirect('/modules/auth/login.php');
