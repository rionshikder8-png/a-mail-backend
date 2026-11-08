<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/services/email_service.php';

require_method('POST');
$u = auth_user();
require_verified($u);
rate_limit('send:' . $u['id'], 60, 3600);
$p = compose_payload_from_input();
$srcId = input_int('forward_of', $p['in_reply_to_id']);
$orig = q_one('SELECT id, subject FROM emails WHERE id = ? AND user_id = ?', [$srcId, $u['id']]);
if (!$orig) {
    fail('not_found', 'The message to forward was not found.', 404);
}
$p['in_reply_to_id'] = 0; // a forward starts a new thread
if ($p['subject'] === '') {
    $p['subject'] = preg_match('/^fwd?:/i', $orig['subject']) ? $orig['subject'] : 'Fwd: ' . $orig['subject'];
}
// carry over the original's attachments if requested
if (input_bool('include_attachments', true)) {
    foreach (q_all('SELECT * FROM attachments WHERE email_id = ? AND user_id = ?', [$orig['id'], $u['id']]) as $a) {
        $src = attachment_abs_path($a['storage_path']);
        if (!$src) {
            continue;
        }
        $rel = $u['id'] . '/' . gmdate('Y') . '/' . random_token(16) . '.bin';
        $dst = storage_path('attachments/' . $rel);
        if (!is_dir(dirname($dst))) {
            mkdir(dirname($dst), 0750, true);
        }
        if (copy($src, $dst)) {
            q('INSERT INTO attachments (user_id, email_id, file_name, mime_type, size_bytes, storage_path, sha256) VALUES (?,NULL,?,?,?,?,?)',
                [$u['id'], $a['file_name'], $a['mime_type'], $a['size_bytes'], $rel, $a['sha256']]);
            $p['attachment_ids'][] = (int)db()->lastInsertId();
        }
    }
}
json_out(['success' => true, 'data' => email_send($u, $p), 'error' => null], 201);
