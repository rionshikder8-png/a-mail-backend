<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/services/email_service.php';

require_method('POST');
$u = auth_user();
$uid = (int)$u['id'];
$labelId = input_int('label_id', 0);
$add = input_bool('add', true);
if (!q_val('SELECT 1 FROM labels WHERE id = ? AND user_id = ?', [$labelId, $uid])) {
    fail('not_found', 'Label not found.', 404);
}
$ids = owned_email_ids($uid, ids_from_input()); // ownership enforced: you can only label your own mail
foreach ($ids as $id) {
    if ($add) {
        q('INSERT IGNORE INTO email_labels (email_id, label_id) VALUES (?,?)', [$id, $labelId]);
    } else {
        q('DELETE FROM email_labels WHERE email_id = ? AND label_id = ?', [$id, $labelId]);
    }
}
ok(['updated' => count($ids)]);
