<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

redirect(admin_current() ? '/admin/dashboard.php' : '/admin/login.php');
