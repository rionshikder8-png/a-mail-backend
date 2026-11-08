<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/services/notification_service.php';

require_method('GET');
$u = auth_user();
$rows = q_all('SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 100', [$u['id']]);
ok(array_map('notification_dto', $rows));
