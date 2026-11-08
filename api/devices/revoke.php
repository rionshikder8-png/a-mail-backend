<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/auth.php';

require_method('POST');
$u = auth_user();
$id = input_int('id', 0);
$dev = q_one('SELECT id FROM devices WHERE id = ? AND user_id = ? AND revoked_at IS NULL', [$id, $u['id']]);
if (!$dev) {
    fail('not_found', 'Device not found.', 404);
}
foreach (q_all('SELECT id FROM sessions WHERE device_id = ? AND revoked_at IS NULL', [$id]) as $s) {
    session_revoke((int)$s['id']);
}
q('UPDATE devices SET revoked_at = UTC_TIMESTAMP(), push_token = NULL WHERE id = ?', [$id]);
security_log((int)$u['id'], 'device_revoked', 'info', ['device_id' => $id]);
ok(null);
