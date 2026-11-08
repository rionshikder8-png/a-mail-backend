<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/auth.php';

require_method('GET');
$u = auth_user();
$rows = q_all('SELECT id, device_name, platform, last_active, last_ip, created_at FROM devices WHERE user_id = ? AND revoked_at IS NULL ORDER BY last_active DESC', [$u['id']]);
ok(array_map(static fn($d) => [
    'id' => (string)$d['id'],
    'device_name' => $d['device_name'],
    'platform' => $d['platform'],
    'last_active' => db_to_iso($d['last_active']),
    'current' => (int)$d['id'] === (int)$u['device_id'],
], $rows));
