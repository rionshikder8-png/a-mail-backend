<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/upload_helpers.php';

require_method('GET', 'POST');
$u = auth_user();
$uid = (int)$u['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        fail('validation', 'Choose an image to upload.', 422);
    }
    $f = $_FILES['file'];
    if ($f['size'] > 2 * 1024 * 1024) {
        fail('payload_too_large', 'Images must be 2 MB or smaller.', 413);
    }
    $mime = sniff_mime($f['tmp_name']);
    if (!isset(ALLOWED_AVATAR_TYPES[$mime]) || @getimagesize($f['tmp_name']) === false) {
        fail('validation', 'Use a JPEG, PNG or WebP image.', 422);
    }
    $name = $uid . '-' . random_token(8) . '.' . ALLOWED_AVATAR_TYPES[$mime];
    if (!move_uploaded_file($f['tmp_name'], storage_path('avatars/' . $name))) {
        fail('server_error', 'Could not save the image.', 500);
    }
    $old = q_val('SELECT avatar_path FROM user_profiles WHERE user_id = ?', [$uid]);
    if ($old) {
        @unlink(storage_path('avatars/' . basename((string)$old)));
    }
    q('UPDATE user_profiles SET avatar_path = ? WHERE user_id = ?', [$name, $uid]);
    ok(user_dto($uid));
}

$target = input_int('id', $uid);
$path = q_val('SELECT avatar_path FROM user_profiles WHERE user_id = ?', [$target]);
$abs = $path ? storage_path('avatars/' . basename((string)$path)) : null;
if (!$abs || !is_file($abs)) {
    fail('not_found', 'No avatar.', 404);
}
header_remove('Content-Type');
header('Content-Type: ' . sniff_mime($abs));
header('Cache-Control: private, max-age=3600');
header('Content-Length: ' . filesize($abs));
readfile($abs);
exit;
