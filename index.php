<?php
require_once __DIR__ . '/config/app.php';
redirect(is_logged_in() ? dashboard_path() : '/modules/portal/index.php');
