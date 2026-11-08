<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/auth.php';

require_method('POST');
$u = auth_user();
$id = input_int('id', 0);
$name = input_str('name', '', 80);
$color = input_int('color', 0xFF1F6BFF) & 0xFFFFFFFF;
if (!q_val('SELECT 1 FROM labels WHERE id = ? AND user_id = ?', [$id, $u['id']])) {
    fail('not_found', 'Label not found.', 404);
}
if ($name === '') {
    fail('validation', 'Enter a label name.', 422, ['name' => 'required']);
}
try {
    q('UPDATE labels SET name = ?, color = ? WHERE id = ? AND user_id = ?', [$name, $color, $id, $u['id']]);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        fail('validation', 'A label with that name already exists.', 409, ['name' => 'duplicate']);
    }
    throw $e;
}
$cnt = (int)q_val('SELECT COUNT(*) FROM email_labels WHERE label_id = ?', [$id]);
ok(['id' => (string)$id, 'name' => $name, 'color' => $color, 'count' => $cnt]);
