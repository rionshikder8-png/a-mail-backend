<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/services/user_service.php';

require_method('GET', 'POST');
rate_limit('uname:' . client_ip(), 60, 60);
$r = username_available(input_str('username', '', 60));
ok(['available' => $r['available'], 'reason' => $r['reason'] ?? null, 'address' => $r['address'] ?? null]);
