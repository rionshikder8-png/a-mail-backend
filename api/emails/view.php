<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/services/email_service.php';

require_method('GET');
$u = auth_user();
$uid = (int)$u['id'];

// Whole conversation: ?thread_id=
if (($tid = input_int('thread_id', 0)) > 0) {
    $rows = q_all("SELECT * FROM emails WHERE user_id = ? AND thread_id = ? AND folder NOT IN ('trash','spam') ORDER BY received_at, id", [$uid, $tid]);
    if (!$rows) {
        fail('not_found', 'Conversation not found.', 404);
    }
    ok(email_dtos($rows, true));
}

$row = q_one('SELECT * FROM emails WHERE id = ? AND user_id = ?', [input_int('id', 0), $uid]);
if (!$row) {
    fail('not_found', 'Message not found.', 404);
}
if (!(int)$row['is_read'] && !(int)$row['is_draft']) {
    q('UPDATE emails SET is_read = 1 WHERE id = ?', [$row['id']]);
    $row['is_read'] = 1;
}
ok(email_dtos([$row], true)[0]);
