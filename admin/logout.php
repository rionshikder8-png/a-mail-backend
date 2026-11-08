<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

if (admin_current()) {
    admin_log('logout');
}
admin_session_destroy();
redirect('/admin/login.php');
