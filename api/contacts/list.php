<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/auth.php';

require_method('GET');
$u = auth_user();
$rows = q_all('SELECT id, name, email, phone, note, favourite FROM contacts WHERE user_id = ? ORDER BY favourite DESC, name', [$u['id']]);
ok(array_map(static fn($c) => ['id' => (string)$c['id'], 'name' => $c['name'], 'email' => $c['email'], 'phone' => $c['phone'], 'note' => $c['note'], 'favourite' => (bool)$c['favourite']], $rows));
