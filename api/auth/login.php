<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/services/auth_service.php';
require_once dirname(__DIR__, 2) . '/services/email_service.php';

require_method('POST');
$identifier = input_str('username', '', 254);
$password = (string)input('password', '');
$device = input_str('device_name', 'Unknown device', 120);
$platform = input_str('platform', 'android', 10);
if ($identifier === '' || $password === '') {
    fail('validation', 'Enter your username and password.', 422);
}

$user = login_attempt($identifier, $password, $device, $platform);
$uid = (int)$user['id'];

if ((int)$user['two_factor_enabled'] === 1) {
    [$code, $challenge] = otp_issue($uid, 'two_factor', true);
    send_otp_message($uid, 'two_factor', $code);
    record_login($uid, $identifier, true, $device, 'two_factor_pending');
    ok(['two_factor_required' => true, 'challenge_id' => $challenge]);
}

$tokens = session_start_for($uid, $device, $platform);
record_login($uid, $identifier, true, $device);
if (setting('login_alerts', '1') === '1' && (int)q_val('SELECT COUNT(*) FROM devices WHERE user_id = ? AND created_at > (UTC_TIMESTAMP() - INTERVAL 1 MINUTE)', [$uid]) > 0
    && (int)q_val('SELECT COUNT(*) FROM devices WHERE user_id = ?', [$uid]) > 1) {
    notify_user($uid, 'security', 'New sign-in', "A new device signed in: $device");
}
ok($tokens + ['user' => user_dto($uid)]);
