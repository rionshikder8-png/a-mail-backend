<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/services/email_service.php';

require_method('POST');
$u = auth_user();
$uid = (int)$u['id'];
rate_limit('draft:' . $uid, 240, 600);
// Drafts may have invalid/partial recipients, so parse leniently
$lenient = static function (mixed $v): array {
    $out = [];
    foreach ((is_array($v) ? $v : []) as $r) {
        $addr = strtolower(trim(is_array($r) ? (string)($r['address'] ?? '') : (string)$r));
        if (valid_email($addr)) {
            $out[] = ['name' => is_array($r) ? mb_substr(header_safe((string)($r['name'] ?? '')), 0, 190) : '', 'address' => $addr];
        }
    }
    return $out;
};
$to = $lenient(input('to', []));
$cc = $lenient(input('cc', []));
$bcc = $lenient(input('bcc', []));
$subject = header_safe(mb_substr((string)input('subject', ''), 0, 900));
$html = sanitize_html((string)input('body_html', ''));
$text = (string)input('body_text', '');
if (strlen($html) > 2 * 1024 * 1024 || strlen($text) > 2 * 1024 * 1024) {
    fail('payload_too_large', 'Draft is too large.', 413);
}
$profile = q_one('SELECT display_name FROM user_profiles WHERE user_id = ?', [$uid]);
$draftId = input_int('draft_id', 0);

$id = db_tx(function () use ($uid, $u, $to, $cc, $bcc, $subject, $html, $text, $profile, $draftId) {
    $existing = $draftId ? q_one('SELECT id FROM emails WHERE id = ? AND user_id = ? AND is_draft = 1', [$draftId, $uid]) : null;
    if ($existing) {
        $id = (int)$existing['id'];
        q('UPDATE emails SET subject = ?, body_html = ?, body_text = ?, snippet = ?, size_bytes = ?, received_at = UTC_TIMESTAMP() WHERE id = ?',
            [$subject, $html, $text, make_snippet($text, $html), strlen($html) + strlen($text), $id]);
        q('DELETE FROM email_recipients WHERE email_id = ?', [$id]);
        foreach (['to' => $to, 'cc' => $cc, 'bcc' => $bcc] as $kind => $list) {
            foreach ($list as $r) {
                q('INSERT INTO email_recipients (email_id, kind, name, address) VALUES (?,?,?,?)', [$id, $kind, $r['name'], $r['address']]);
            }
        }
    } else {
        $id = mailbox_store($uid, [
            'message_id' => new_message_id(), 'from' => ['name' => (string)($profile['display_name'] ?? ''), 'address' => user_address($u['username'])],
            'to' => $to, 'cc' => $cc, 'bcc' => $bcc, 'subject' => $subject, 'html' => $html, 'text' => $text,
            'folder' => 'drafts', 'is_read' => true, 'is_draft' => true,
        ]);
    }
    $att = pending_attachments($uid, (array)input('attachment_ids', []));
    foreach ($att as $a) {
        q('UPDATE attachments SET email_id = ? WHERE id = ?', [$id, $a['id']]);
    }
    return $id;
});
ok(['id' => (string)$id]);
