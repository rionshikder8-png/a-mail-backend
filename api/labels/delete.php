<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/auth.php';

require_method('POST');
$u = auth_user();
$n = q('DELETE FROM labels WHERE id = ? AND user_id = ?', [input_int('id', 0), $u['id']])->rowCount();
if (!$n) {
    fail('not_found', 'Label not found.', 404);
}
ok(null);
