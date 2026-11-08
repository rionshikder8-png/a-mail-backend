<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/auth.php';

require_method('GET', 'POST');
$u = auth_user();
ok(user_dto((int)$u['id']));
