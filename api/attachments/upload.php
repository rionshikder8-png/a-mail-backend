<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/services/attachment_service.php';

require_method('POST');
$u = auth_user();
$uid = (int)$u['id'];
rate_limit('upload:' . $uid, 60, 600);
if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
    fail('validation', 'Choose a file to attach.', 422);
}
$f = $_FILES['file'];
if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
    fail('payload_too_large', 'That file is too large.', 413);
}
if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
    fail('upload_failed', 'The upload did not complete. Please try again.', 400);
}
$plan = q_one('SELECT sp.max_attachment_bytes FROM users u LEFT JOIN subscription_plans sp ON sp.id = u.plan_id WHERE u.id = ?', [$uid]);
$max = (int)($plan['max_attachment_bytes'] ?? config('max_attachment'));
if ($f['size'] <= 0) {
    fail('validation', 'That file is empty.', 422);
}
if ($f['size'] > $max) {
    fail('payload_too_large', 'Attachments can be up to ' . format_bytes($max) . ' on your plan.', 413);
}
$name = safe_filename((string)$f['name']);
if (is_blocked_extension($name)) {
    fail('file_type_blocked', 'This file type cannot be attached for security reasons.', 422);
}
assert_quota_for($uid, (int)$f['size']);
$mime = sniff_mime($f['tmp_name']);
$stored = store_attachment_file($uid, $f['tmp_name'], $name, $mime);
q('INSERT INTO attachments (user_id, email_id, file_name, mime_type, size_bytes, storage_path, sha256) VALUES (?,NULL,?,?,?,?,?)',
    [$uid, $stored['name'], $stored['mime'], $stored['size'], $stored['path'], $stored['sha256']]);
$id = (int)db()->lastInsertId();
json_out(['success' => true, 'data' => attachment_dto(['id' => $id, 'file_name' => $stored['name'], 'mime_type' => $stored['mime'], 'size_bytes' => $stored['size']]), 'error' => null], 201);
