<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/services/attachment_service.php';

require_method('POST');
$u = auth_user();
$a = q_one('SELECT * FROM attachments WHERE id = ? AND user_id = ?', [input_int('id', 0), $u['id']]);
if (!$a) {
    fail('not_found', 'Attachment not found.', 404);
}
delete_attachment_file($a['storage_path']);
q('DELETE FROM attachments WHERE id = ?', [$a['id']]);
if ($a['email_id']) {
    q('UPDATE emails SET has_attachments = EXISTS(SELECT 1 FROM attachments WHERE email_id = ?) WHERE id = ?', [$a['email_id'], $a['email_id']]);
}
ok(null);
