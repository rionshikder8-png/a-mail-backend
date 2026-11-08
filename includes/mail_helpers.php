<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

function new_message_id(): string
{
    return bin2hex(random_bytes(12)) . '.' . time() . '@' . config('domain');
}

function normalize_subject(string $s): string
{
    $s = trim($s);
    while (preg_match('/^(re|fwd?|aw|sv)\s*:\s*/i', $s)) {
        $s = preg_replace('/^(re|fwd?|aw|sv)\s*:\s*/i', '', $s) ?? $s;
    }
    return mb_strtolower(mb_substr(trim($s), 0, 250));
}

/**
 * Sanitise untrusted HTML mail for storage/display: allow-list of tags and attributes,
 * no scripts, no event handlers, no javascript: URLs, no remote forms/frames.
 */
function sanitize_html(string $html): string
{
    if ($html === '') {
        return '';
    }
    $html = preg_replace('#<(script|style|iframe|object|embed|form|link|meta|base|svg|math)\b[^>]*>.*?</\1\s*>#is', '', $html) ?? '';
    $html = preg_replace('#<(script|style|iframe|object|embed|form|link|meta|base|input|button|textarea|select)\b[^>]*/?>#is', '', $html) ?? '';
    $allowed = '<p><br><b><strong><i><em><u><s><ul><ol><li><a><blockquote><pre><code><h1><h2><h3><h4><span><div><table><thead><tbody><tr><td><th><img><hr><sub><sup>';
    $html = strip_tags($html, $allowed);
    // drop every attribute except a safe few
    $html = preg_replace_callback('#<([a-z0-9]+)((?:\s+[^>]*?)?)(/?)>#i', static function ($m) {
        $tag = strtolower($m[1]);
        $keep = '';
        if (preg_match_all('#\s([a-z\-:]+)\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))#i', $m[2], $attrs, PREG_SET_ORDER)) {
            foreach ($attrs as $a) {
                $name = strtolower($a[1]);
                $val = html_entity_decode($a[3] !== '' ? $a[3] : ($a[4] !== '' ? $a[4] : ($a[5] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($tag === 'a' && $name === 'href' && preg_match('#^(https?://|mailto:)#i', trim($val))) {
                    $keep .= ' href="' . htmlspecialchars(trim($val), ENT_QUOTES) . '" rel="noopener noreferrer nofollow" target="_blank"';
                } elseif ($tag === 'img' && $name === 'src' && preg_match('#^https://#i', trim($val))) {
                    $keep .= ' src="' . htmlspecialchars(trim($val), ENT_QUOTES) . '" loading="lazy" referrerpolicy="no-referrer"';
                } elseif ($tag === 'img' && in_array($name, ['alt', 'width', 'height'], true)) {
                    $keep .= ' ' . $name . '="' . htmlspecialchars($val, ENT_QUOTES) . '"';
                } elseif (in_array($name, ['colspan', 'rowspan'], true) && ctype_digit($val)) {
                    $keep .= ' ' . $name . '="' . $val . '"';
                }
            }
        }
        return '<' . $tag . $keep . $m[3] . '>';
    }, $html) ?? '';
    return $html;
}

/** Resolve (or create) the thread for a message in one user's mailbox. */
function resolve_thread(int $userId, string $subject, ?string $inReplyTo, string $references = ''): int
{
    $ids = [];
    if ($inReplyTo) {
        $ids[] = $inReplyTo;
    }
    foreach (preg_split('/\s+/', trim($references)) ?: [] as $r) {
        $r = trim($r, '<> ');
        if ($r !== '') {
            $ids[] = $r;
        }
    }
    foreach (array_slice(array_reverse($ids), 0, 10) as $mid) {
        $tid = q_val('SELECT thread_id FROM emails WHERE user_id = ? AND message_id = ? LIMIT 1', [$userId, $mid]);
        if ($tid) {
            return (int)$tid;
        }
    }
    q('INSERT INTO email_threads (user_id, subject_norm, last_message_at, message_count) VALUES (?,?,UTC_TIMESTAMP(),0)', [$userId, normalize_subject($subject)]);
    return (int)db()->lastInsertId();
}

function touch_thread(int $threadId): void
{
    q('UPDATE email_threads SET message_count = (SELECT COUNT(*) FROM emails WHERE thread_id = ? AND folder <> "trash"), last_message_at = UTC_TIMESTAMP() WHERE id = ?', [$threadId, $threadId]);
}

/** Rebuild the "Name <addr>" form used in headers, with CR/LF stripped. */
function format_address(string $name, string $address): string
{
    $name = header_safe($name);
    if ($name === '') {
        return $address;
    }
    return mb_encode_mimeheader($name, 'UTF-8', 'B', "\r\n") . ' <' . $address . '>';
}
