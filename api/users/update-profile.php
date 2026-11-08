<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/auth.php';

require_method('POST');
$u = auth_user();
$uid = (int)$u['id'];
$sets = [];
$args = [];
$fields = [];

if (array_key_exists('display_name', body())) {
    $v = input_str('display_name', '', 100);
    if ($v === '') {
        $fields['display_name'] = 'Name cannot be empty.';
    } else {
        $sets[] = 'display_name = ?';
        $args[] = $v;
    }
}
if (array_key_exists('recovery_email', body())) {
    $v = strtolower(input_str('recovery_email', '', 254));
    if ($v !== '' && (!valid_email($v) || str_ends_with($v, '@' . config('domain')))) {
        $fields['recovery_email'] = 'Use a valid email address outside A-Mail.';
    } else {
        $sets[] = 'recovery_email = ?';
        $args[] = $v ?: null;
    }
}
if (array_key_exists('recovery_phone', body())) {
    $v = input_str('recovery_phone', '', 32);
    if ($v !== '' && !preg_match('/^\+?[0-9 ()\-]{6,24}$/', $v)) {
        $fields['recovery_phone'] = 'Enter a valid phone number.';
    } else {
        $sets[] = 'recovery_phone = ?';
        $args[] = $v ?: null;
    }
}
if (array_key_exists('signature', body())) {
    $sets[] = 'signature = ?';
    $args[] = mb_substr((string)input('signature', ''), 0, 2000);
}
if (array_key_exists('language', body())) {
    $v = input_str('language', 'en', 8);
    if (!preg_match('/^[a-z]{2}(-[A-Za-z]{2})?$/', $v)) {
        $fields['language'] = 'Unsupported language.';
    } else {
        $sets[] = 'language = ?';
        $args[] = $v;
    }
}
if ($fields) {
    fail('validation', 'Please fix the highlighted fields.', 422, $fields);
}
if ($sets) {
    $args[] = $uid;
    q('UPDATE user_profiles SET ' . implode(', ', $sets) . ' WHERE user_id = ?', $args);
    if (array_key_exists('recovery_email', body())) {
        q('UPDATE users SET email_verified = 0 WHERE id = ?', [$uid]);
    }
}
ok(user_dto($uid));
