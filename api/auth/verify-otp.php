<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/services/auth_service.php';

require_method('POST');
$identifier = input_str('identifier', '', 254);
$code = input_str('code', '', 10);
$purpose = input_str('purpose', 'reset_password', 30);
if (!in_array($purpose, ['reset_password', 'recovery'], true)) {
    fail('validation', 'Unsupported purpose.', 422);
}
if (!preg_match('/^\d{6}$/', $code)) {
    fail('validation', 'Enter the 6-digit code.', 422);
}
rate_limit('otp:' . strtolower($identifier), 10, 900);
$user = find_user_by_identifier($identifier);
if (!$user) {
    fail('invalid_code', 'That code is incorrect or has expired.', 401);
}
// Check without consuming: reset-password will verify again with the same code, so peek here.
$row = q_one('SELECT * FROM otp_codes WHERE user_id = ? AND purpose = ? AND consumed_at IS NULL AND expires_at > UTC_TIMESTAMP() ORDER BY id DESC LIMIT 1', [$user['id'], $purpose]);
$good = $row && (int)$row['attempts'] < 5 && hash_equals($row['code_hash'], token_hash($user['id'] . ':' . $purpose . ':' . $code));
if (!$good) {
    if ($row) {
        q('UPDATE otp_codes SET attempts = attempts + 1 WHERE id = ?', [$row['id']]);
    }
    fail('invalid_code', 'That code is incorrect or has expired.', 401);
}
ok(['valid' => true]);
