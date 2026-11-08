<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/services/email_service.php';

/**
 * Detect a bounce (DSN, RFC 3464) in a parsed inbound message.
 * Returns ['recipient'=>..., 'status'=>'5.1.1', 'diagnostic'=>...] or null if it is not a bounce.
 */
function detect_bounce(array $parsed, string $raw): ?array
{
    $ct = strtolower($parsed['headers']['content-type'][0] ?? '');
    $from = strtolower($parsed['from']['address'] ?? '');
    $looksLikeDsn = str_contains($ct, 'multipart/report') || str_contains($ct, 'delivery-status')
        || str_starts_with($from, 'mailer-daemon@') || str_starts_with($from, 'postmaster@');
    if (!$looksLikeDsn) {
        return null;
    }
    $rcpt = null;
    $status = null;
    $diag = null;
    if (preg_match('/^Final-Recipient:\s*rfc822;\s*(\S+)/mi', $raw, $m)) {
        $rcpt = strtolower(trim($m[1], '<>'));
    } elseif (preg_match('/^Original-Recipient:\s*rfc822;\s*(\S+)/mi', $raw, $m)) {
        $rcpt = strtolower(trim($m[1], '<>'));
    }
    if (preg_match('/^Status:\s*(\d\.\d+\.\d+)/mi', $raw, $m)) {
        $status = $m[1];
    }
    if (preg_match('/^Diagnostic-Code:\s*[^;]*;\s*(.+)$/mi', $raw, $m)) {
        $diag = trim($m[1]);
    }
    if ($rcpt === null) {
        return null;
    }
    return ['recipient' => $rcpt, 'status' => $status ?? '5.0.0', 'diagnostic' => $diag ?? 'Delivery failed'];
}

/** Apply a bounce to the original queue row so the sender sees an honest failure. Only hard bounces (5.x.x) fail the message. */
function apply_bounce(array $bounce): bool
{
    $hard = str_starts_with($bounce['status'], '5');
    $row = q_one('SELECT q.*, e.user_id AS owner, e.subject FROM mail_queue q JOIN emails e ON e.id = q.email_id
                  WHERE q.rcpt_address = ? AND q.status = "sent" ORDER BY q.id DESC LIMIT 1', [$bounce['recipient']]);
    q('INSERT INTO delivery_logs (queue_id, rcpt_address, status, smtp_code, response) VALUES (?,?,?,?,?)',
        [$row['id'] ?? null, $bounce['recipient'], $hard ? 'bounced' : 'deferred', $bounce['status'], mb_substr($bounce['diagnostic'], 0, 500)]);
    if (!$row || !$hard) {
        return false;
    }
    q('UPDATE mail_queue SET status = "failed", last_error = ? WHERE id = ?', [mb_substr($bounce['diagnostic'], 0, 500), $row['id']]);
    q('UPDATE emails SET sent_state = "failed" WHERE id = ?', [$row['email_id']]);
    notify_user((int)$row['owner'], 'system', 'Message bounced', 'To ' . $bounce['recipient'] . ': ' . mb_substr($bounce['diagnostic'], 0, 200), (int)$row['email_id']);
    return true;
}
