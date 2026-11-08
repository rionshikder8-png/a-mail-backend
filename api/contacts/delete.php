<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/auth.php';

require_method('POST');
$u = auth_user();
if (!q('DELETE FROM contacts WHERE id = ? AND user_id = ?', [input_int('id', 0), $u['id']])->rowCount()) {
    fail('not_found', 'Contact not found.', 404);
}
ok(null);
