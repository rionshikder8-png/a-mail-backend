<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/services/auth_service.php';
require_once dirname(__DIR__, 2) . '/services/email_service.php';

require_method('POST');

// Authenticated toggle: {enable: bool}
if (isset(body()['enable'])) {
    $u = auth_user();
    $enable = input_bool('enable');
    q('UPDATE users SET two_factor_enabled = ? WHERE id = ?', [$enable ? 1 : 0, $u['id']]);
    security_log((int)$u['id'], $enable ? 'two_factor_enabled' : 'two_factor_disabled', 'warning');
    notify_user((int)$u['id'], 'security', $enable ? 'Two-factor sign-in turned on' : 'Two-factor sign-in turned off', '');
    ok(['two_factor_enabled' => $enable]);
}

// Login completion: {challenge_id, code}
$challenge = input_str('challenge_id', '', 64);
$code = input_str('code', '', 10);
if ($challenge === '' || !preg_match('/^\d{6}$/', $code)) {
    fail('validation', 'Enter the 6-digit code.', 422);
}
rate_limit('2fa:' . $challenge, 8, 600);
$row = q_one('SELECT user_id FROM otp_codes WHERE challenge_id = ? AND purpose = "two_factor"', [$challenge]);
if (!$row || !otp_verify((int)$row['user_id'], 'two_factor', $code, $challenge)) {
    fail('invalid_code', 'That code is incorrect or has expired.', 401);
}
$uid = (int)$row['user_id'];
$status = q_val('SELECT status FROM users WHERE id = ?', [$uid]);
if ($status !== 'active') {
    fail('account_suspended', 'This account is not active.', 403);
}
$tokens = session_start_for($uid, input_str('device_name', 'Unknown device', 120), input_str('platform', 'android', 10));
record_login($uid, (string)$uid, true, null, 'two_factor');
ok($tokens + ['user' => user_dto($uid)]);
