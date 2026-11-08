<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/services/user_service.php';
require_once dirname(__DIR__, 2) . '/services/notification_service.php';

require_method('POST');
$u = auth_user();
$uid = (int)$u['id'];
rate_limit('chpw:' . $uid, 8, 900);
$cur = (string)input('current_password', '');
$new = (string)input('new_password', '');
$hash = (string)q_val('SELECT password_hash FROM users WHERE id = ?', [$uid]);
if (!password_verify($cur, $hash)) {
    fail('invalid_credentials', 'Your current password is incorrect.', 401, ['current_password' => 'Incorrect password.']);
}
if ($err = validate_password($new)) {
    fail('validation', $err, 422, ['new_password' => $err]);
}
if ($cur === $new) {
    fail('validation', 'Choose a password you have not used just now.', 422, ['new_password' => 'Must be different.']);
}
user_set_password($uid, $new);
sessions_revoke_all($uid, (int)$u['session_id']); // keep this device, sign out the rest
security_log($uid, 'password_changed', 'warning');
notify_user($uid, 'security', 'Password changed', 'Other devices were signed out.');
ok(null);
