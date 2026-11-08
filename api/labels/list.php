<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/auth.php';

require_method('GET');
$u = auth_user();
$rows = q_all('SELECT l.id, l.name, l.color,
                (SELECT COUNT(*) FROM email_labels el JOIN emails e ON e.id = el.email_id WHERE el.label_id = l.id AND e.folder NOT IN ("trash","spam")) AS cnt
               FROM labels l WHERE l.user_id = ? ORDER BY l.name', [$u['id']]);
ok(array_map(static fn($l) => ['id' => (string)$l['id'], 'name' => $l['name'], 'color' => (int)$l['color'], 'count' => (int)$l['cnt']], $rows));
