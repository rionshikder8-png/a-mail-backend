<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/auth.php';

require_method('POST');
rate_limit('refresh:ip:' . client_ip(), 60, 60);
$rt = input_str('refresh_token', '', 200);
if ($rt === '') {
    fail('validation', 'Missing refresh token.', 422);
}
ok(refresh_rotate($rt));
