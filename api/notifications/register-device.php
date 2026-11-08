<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/auth.php';

require_method('POST');
$u = auth_user();
$token = input_str('push_token', '', 512);
if ($token === '') {
    fail('validation', 'Missing push token.', 422);
}
$deviceId = $u['device_id'] ? (int)$u['device_id'] : find_device((int)$u['id'], input_str('device_name', 'Unknown device', 120), input_str('platform', 'android', 10));
// a push token belongs to exactly one device
q('UPDATE devices SET push_token = NULL WHERE push_token = ? AND id <> ?', [$token, $deviceId]);
q('UPDATE devices SET push_token = ?, last_active = UTC_TIMESTAMP() WHERE id = ? AND user_id = ?', [$token, $deviceId, $u['id']]);
ok(['registered' => true]);
