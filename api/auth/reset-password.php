<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/services/auth_service.php';

require_method('POST');
$identifier = input_str('identifier', '', 254);
$code = input_str('code', '', 10);
$new = (string)input('new_password', '');
if ($err = validate_password($new)) {
    fail('validation', $err, 422, ['new_password' => $err]);
}
if (!preg_match('/^\d{6}$/', $code)) {
    fail('validation', 'Enter the 6-digit code.', 422);
}
rate_limit('reset:' . strtolower($identifier), 10, 900);
$user = find_user_by_identifier($identifier);
if (!$user || !otp_verify((int)$user['id'], 'reset_password', $code)) {
    fail('invalid_code', 'That code is incorrect or has expired.', 401);
}
user_set_password((int)$user['id'], $new);
sessions_revoke_all((int)$user['id']);
security_log((int)$user['id'], 'password_reset_completed', 'warning');
notify_user((int)$user['id'], 'security', 'Password changed', 'Your password was reset and all devices were signed out.');
ok(null);
