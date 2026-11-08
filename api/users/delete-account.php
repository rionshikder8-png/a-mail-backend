<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/services/user_service.php';

require_method('POST');
$u = auth_user();
$uid = (int)$u['id'];
rate_limit('delacct:' . $uid, 5, 3600);
$hash = (string)q_val('SELECT password_hash FROM users WHERE id = ?', [$uid]);
if (!password_verify((string)input('password', ''), $hash)) {
    fail('invalid_credentials', 'Your password is incorrect.', 401, ['password' => 'Incorrect password.']);
}
security_log($uid, 'account_deleted', 'warning', ['username' => $u['username']]);
user_purge($uid);
ok(null);
