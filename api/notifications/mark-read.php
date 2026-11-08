<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/auth.php';

require_method('POST');
$u = auth_user();
$ids = ids_from_input();
if ($ids) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    q("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND id IN ($in)", array_merge([$u['id']], $ids));
} else {
    q('UPDATE notifications SET is_read = 1 WHERE user_id = ?', [$u['id']]);
}
ok(null);
