<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/services/auth_service.php';
require_once dirname(__DIR__, 2) . '/services/email_service.php';

require_method('POST');
$u = auth_user();
$uid = (int)$u['id'];

if (input_bool('resend')) {
    rate_limit('verify-resend:' . $uid, 3, 3600);
    [$code] = otp_issue($uid, 'verify_email');
    send_otp_message($uid, 'verify_email', $code);
    ok(['sent' => true]);
}
$code = input_str('code', '', 10);
if (!preg_match('/^\d{6}$/', $code)) {
    fail('validation', 'Enter the 6-digit code.', 422);
}
rate_limit('verify:' . $uid, 10, 900);
if (!otp_verify($uid, 'verify_email', $code)) {
    fail('invalid_code', 'That code is incorrect or has expired.', 401);
}
q('UPDATE users SET email_verified = 1 WHERE id = ?', [$uid]);
security_log($uid, 'email_verified');
ok(['email_verified' => true]);
