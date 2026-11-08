<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/auth.php';

require_method('GET');
$u = auth_user();
$rows = q_all('SELECT id, device_name, ip, success, created_at FROM login_history WHERE user_id = ? ORDER BY id DESC LIMIT 50', [$u['id']]);
ok(array_map(static fn($r) => [
    'id' => (string)$r['id'],
    'device_name' => $r['device_name'],
    'ip' => $r['ip'],
    'success' => (bool)$r['success'],
    'created_at' => db_to_iso($r['created_at']),
], $rows));
