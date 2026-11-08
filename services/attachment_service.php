<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/upload_helpers.php';

function attachment_dto(array $a): array
{
    return [
        'id' => (string)$a['id'],
        'file_name' => $a['file_name'],
        'mime_type' => $a['mime_type'],
        'size_bytes' => (int)$a['size_bytes'],
        'url' => rtrim((string)config('url'), '/') . '/v1/attachments/download?id=' . $a['id'],
    ];
}

/** Save inbound-parsed attachment bytes (from an incoming message) to disk + DB, owned by $userId. */
function attachment_save_blob(int $userId, int $emailId, string $name, string $mime, string $data): ?int
{
    if ($data === '' || is_blocked_extension($name)) {
        return null; // risky attachment types are dropped, never stored
    }
    $tmp = tempnam(storage_path('temporary'), 'att');
    file_put_contents($tmp, $data);
    try {
        $mimeReal = sniff_mime($tmp);
        $f = store_attachment_file($userId, $tmp, $name, $mimeReal !== 'application/octet-stream' ? $mimeReal : $mime);
    } finally {
        @unlink($tmp);
    }
    q('INSERT INTO attachments (user_id, email_id, file_name, mime_type, size_bytes, storage_path, sha256) VALUES (?,?,?,?,?,?,?)',
        [$userId, $emailId, $f['name'], $f['mime'], $f['size'], $f['path'], $f['sha256']]);
    return (int)db()->lastInsertId();
}

/** Copy an existing attachment (used when delivering to a local recipient / forwarding). */
function attachment_clone(array $att, int $newUserId, int $newEmailId): void
{
    $src = attachment_abs_path($att['storage_path']);
    if (!$src) {
        return;
    }
    $rel = $newUserId . '/' . gmdate('Y') . '/' . random_token(16) . '.bin';
    $dst = storage_path('attachments/' . $rel);
    if (!is_dir(dirname($dst))) {
        mkdir(dirname($dst), 0750, true);
    }
    if (!copy($src, $dst)) {
        return;
    }
    @chmod($dst, 0640);
    q('INSERT INTO attachments (user_id, email_id, file_name, mime_type, size_bytes, storage_path, sha256) VALUES (?,?,?,?,?,?,?)',
        [$newUserId, $newEmailId, $att['file_name'], $att['mime_type'], $att['size_bytes'], $rel, $att['sha256']]);
}
