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
if (input_bool('permanent')) {
    emails_delete_forever($uid, $ids);
} elseif (input_str('to') === 'spam') {
    emails_move($uid, $ids, 'spam');
} else {
    emails_move($uid, $ids, 'trash');
}
ok(null);
