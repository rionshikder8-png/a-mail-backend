<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/auth.php';

require_method('POST');
$u = auth_user();
$name = input_str('name', '', 80);
$color = input_int('color', 0xFF1F6BFF);
if ($name === '' || mb_strlen($name) > 80) {
    fail('validation', 'Enter a label name (up to 80 characters).', 422, ['name' => 'required']);
}
if ((int)q_val('SELECT COUNT(*) FROM labels WHERE user_id = ?', [$u['id']]) >= 200) {
    fail('limit_reached', 'You have reached the label limit.', 422);
}
try {
    q('INSERT INTO labels (user_id, name, color) VALUES (?,?,?)', [$u['id'], $name, $color & 0xFFFFFFFF]);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        fail('validation', 'A label with that name already exists.', 409, ['name' => 'duplicate']);
    }
    throw $e;
}
json_out(['success' => true, 'data' => ['id' => (string)db()->lastInsertId(), 'name' => $name, 'color' => $color & 0xFFFFFFFF, 'count' => 0], 'error' => null], 201);
