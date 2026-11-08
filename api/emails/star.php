<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/services/email_service.php';

require_method('POST');
$u = auth_user();
$uid = (int)$u['id'];
$ids = ids_from_input();
if (!$ids) {
    fail('validation', 'No messages selected.', 422);
}
$flag = input_str('flag') === 'important' ? 'is_important' : 'is_starred';
emails_set_flag($uid, $ids, $flag, input_bool('value', true));
ok(null);
