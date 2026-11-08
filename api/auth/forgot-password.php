<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/services/auth_service.php';
require_once dirname(__DIR__, 2) . '/services/email_service.php';

require_method('POST');
rate_limit('forgot:ip:' . client_ip(), 10, 3600);
$identifier = input_str('identifier', '', 254);
if ($identifier === '') {
    fail('validation', 'Enter your A-Mail address.', 422);
}
rate_limit('forgot:id:' . strtolower($identifier), 3, 3600);
$user = find_user_by_identifier($identifier);
if ($user && $user['status'] === 'active') {
    [$code] = otp_issue((int)$user['id'], 'reset_password');
    send_otp_message((int)$user['id'], 'reset_password', $code);
    security_log((int)$user['id'], 'password_reset_requested');
}
// Same answer whether or not the account exists: no account enumeration.
ok(['sent' => true, 'message' => 'If that account exists, a reset code has been sent to its mailbox and recovery email.']);
