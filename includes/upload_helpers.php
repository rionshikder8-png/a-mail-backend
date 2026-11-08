<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once dirname(__DIR__) . '/config/storage_config.php';

/** Detect the real MIME type from content, never trusting the client-supplied one. */
function sniff_mime(string $path): string
{
    $f = new finfo(FILEINFO_MIME_TYPE);
    return (string)($f->file($path) ?: 'application/octet-stream');
}

function safe_filename(string $name): string
{
    $name = basename(str_replace('\\', '/', $name));
    $name = preg_replace('/[\x00-\x1F\x7F<>:"\/\\\\|?*]+/u', '_', $name) ?? 'file';
    $name = trim($name, '. ');
    if ($name === '') {
        $name = 'file';
    }
    return mb_substr($name, 0, 200);
}

function is_blocked_extension(string $name): bool
{
    // check every dot-separated part so "evil.php.jpg" and "a.exe.txt" are still caught
    foreach (explode('.', strtolower($name)) as $i => $part) {
        if ($i > 0 && in_array($part, BLOCKED_EXTENSIONS, true)) {
            return true;
        }
    }
    return false;
}

function user_quota_bytes(int $userId): int
{
    $q = q_val('SELECT quota_bytes FROM storage_quotas WHERE user_id = ?', [$userId]);
    return $q !== null ? (int)$q : (int)config('default_quota');
}

/** Bytes currently counted against the user: stored mail bodies + attachment files. Trash counts until purged. */
function user_used_bytes(int $userId): int
{
    $mail = (int)q_val('SELECT COALESCE(SUM(size_bytes),0) FROM emails WHERE user_id = ?', [$userId]);
    $att = (int)q_val('SELECT COALESCE(SUM(size_bytes),0) FROM attachments WHERE user_id = ?', [$userId]);
    return $mail + $att;
}

function assert_quota_for(int $userId, int $incomingBytes): void
{
    if (user_used_bytes($userId) + $incomingBytes > user_quota_bytes($userId)) {
        fail('quota_exceeded', 'Your storage is full. Free up space or upgrade your plan.', 413);
    }
}

/**
 * Persist an uploaded file for a user. Layout: storage/attachments/<userId>/<yyyy>/<random>.bin
 * Files are stored under random names OUTSIDE any web-served path and are never executed.
 */
function store_attachment_file(int $userId, string $tmpPath, string $origName, string $mime): array
{
    $size = (int)filesize($tmpPath);
    $rel = $userId . '/' . gmdate('Y') . '/' . random_token(16) . '.bin';
    $abs = storage_path('attachments/' . $rel);
    if (!is_dir(dirname($abs)) && !mkdir(dirname($abs), 0750, true) && !is_dir(dirname($abs))) {
        throw new RuntimeException('Cannot create storage directory');
    }
    $moved = is_uploaded_file($tmpPath) ? move_uploaded_file($tmpPath, $abs) : copy($tmpPath, $abs);
    if (!$moved) {
        throw new RuntimeException('Cannot store file');
    }
    @chmod($abs, 0640);
    return ['path' => $rel, 'size' => $size, 'sha256' => hash_file('sha256', $abs), 'mime' => $mime, 'name' => safe_filename($origName)];
}

function attachment_abs_path(string $rel): ?string
{
    $base = realpath(storage_path('attachments'));
    $abs = realpath(storage_path('attachments/' . $rel));
    if ($base === false || $abs === false || !str_starts_with($abs, $base . DIRECTORY_SEPARATOR)) {
        return null; // blocks path traversal
    }
    return $abs;
}

function delete_attachment_file(string $rel): void
{
    $abs = attachment_abs_path($rel);
    if ($abs && is_file($abs)) {
        @unlink($abs);
    }
}
