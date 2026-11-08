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
// Return to where the message came from; spam release goes back to the inbox.
foreach (owned_email_ids($uid, $ids) as $id) {
    $prev = (string)q_val('SELECT prev_folder FROM emails WHERE id = ?', [$id]);
    $dest = in_array($prev, ['inbox', 'sent', 'archive', 'drafts'], true) ? $prev : 'inbox';
    if (input_str('from') === 'spam') {
        $dest = 'inbox';
    }
    emails_move($uid, [$id], $dest);
}
ok(null);
