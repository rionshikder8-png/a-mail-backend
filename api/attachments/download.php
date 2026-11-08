<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/services/attachment_service.php';

require_method('GET');
$u = auth_user();
$a = q_one('SELECT * FROM attachments WHERE id = ? AND user_id = ?', [input_int('id', 0), $u['id']]); // owner only
if (!$a) {
    fail('not_found', 'Attachment not found.', 404);
}
$abs = attachment_abs_path($a['storage_path']);
if (!$abs) {
    fail('not_found', 'The file is no longer available.', 404);
}
// Everything downloads as an attachment with a neutral type: files are never rendered by the browser.
$safeName = preg_replace('/[^\w.\- ]+/u', '_', $a['file_name']) ?: 'file';
header_remove('Content-Type');
header('Content-Type: application/octet-stream');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: attachment; filename="' . $safeName . '"; filename*=UTF-8\'\'' . rawurlencode($a['file_name']));
header('Content-Length: ' . filesize($abs));
header('Cache-Control: private, no-store');
header('X-A-Mail-Mime: ' . preg_replace('/[^\w\/.+\-]/', '', $a['mime_type']));
readfile($abs);
exit;
