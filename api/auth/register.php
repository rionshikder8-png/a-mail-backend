<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/services/auth_service.php';
require_once dirname(__DIR__, 2) . '/services/email_service.php';

require_method('POST');
if (setting('registration_open', '1') !== '1') {
    fail('registration_closed', 'New registrations are currently closed.', 403);
}
rate_limit('register:ip:' . client_ip(), 5, 3600);

$username = strtolower(input_str('username', '', 60));
$password = (string)input('password', '');
$display = input_str('display_name', '', 100);
$recovery = strtolower(input_str('recovery_email', '', 254));
$fields = [];

if ($err = validate_username($username)) {
    $fields['username'] = $err;
}
if ($err = validate_password($password)) {
    $fields['password'] = $err;
}
if ($display === '') {
    $fields['display_name'] = 'Enter your name.';
}
if ($recovery !== '' && !valid_email($recovery)) {
    $fields['recovery_email'] = 'Enter a valid recovery email.';
}
if ($recovery !== '' && str_ends_with($recovery, '@' . config('domain'))) {
    $fields['recovery_email'] = 'Use an email address outside A-Mail so you can recover your account.';
}
if ($fields) {
    fail('validation', 'Please fix the highlighted fields.', 422, $fields);
}

try {
    $uid = user_create($username, $password, $display, $recovery ?: null);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') { // unique violation: lost a race for the name
        fail('validation', 'That username is already taken.', 409, ['username' => 'That username is already taken.']);
    }
    throw $e;
}
security_log($uid, 'account_created');
[$code] = otp_issue($uid, 'verify_email');
send_otp_message($uid, 'verify_email', $code);

$tokens = session_start_for($uid, input_str('device_name', 'Unknown device', 120), input_str('platform', 'android', 10));
record_login($uid, $username, true, input_str('device_name', '', 120), 'register');
json_out(['success' => true, 'data' => $tokens + ['user' => user_dto($uid)], 'error' => null], 201);
