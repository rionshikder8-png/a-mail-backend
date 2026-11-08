<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/mail_helpers.php';
require_once dirname(__DIR__) . '/includes/upload_helpers.php';
require_once dirname(__DIR__) . '/mail/mail-parser.php';
require_once dirname(__DIR__) . '/mail/spam-filter.php';
require_once dirname(__DIR__) . '/mail/domain-authentication.php';
require_once __DIR__ . '/attachment_service.php';
require_once __DIR__ . '/notification_service.php';

// ---------------------------------------------------------------- shaping for the API

function recipients_for(array $emailIds): array
{
    if (!$emailIds) {
        return [];
    }
    $in = implode(',', array_fill(0, count($emailIds), '?'));
    $out = [];
    foreach (q_all("SELECT email_id, kind, name, address FROM email_recipients WHERE email_id IN ($in) ORDER BY id", array_values($emailIds)) as $r) {
        $out[$r['email_id']][$r['kind']][] = ['name' => $r['name'], 'address' => $r['address']];
    }
    return $out;
}

function email_dtos(array $rows, bool $full = false): array
{
    if (!$rows) {
        return [];
    }
    $ids = array_map(static fn($r) => (int)$r['id'], $rows);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $rcpt = recipients_for($ids);

    $labels = [];
    foreach (q_all("SELECT email_id, label_id FROM email_labels WHERE email_id IN ($in)", $ids) as $l) {
        $labels[$l['email_id']][] = (string)$l['label_id'];
    }
    $atts = [];
    foreach (q_all("SELECT id, email_id, file_name, mime_type, size_bytes FROM attachments WHERE email_id IN ($in)", $ids) as $a) {
        $atts[$a['email_id']][] = attachment_dto($a);
    }
    $threadCounts = [];
    $tids = array_values(array_unique(array_map(static fn($r) => (int)$r['thread_id'], $rows)));
    if ($tids) {
        $tin = implode(',', array_fill(0, count($tids), '?'));
        $uid = (int)$rows[0]['user_id'];
        foreach (q_all("SELECT thread_id, COUNT(*) c FROM emails WHERE user_id = ? AND thread_id IN ($tin) AND folder NOT IN ('trash','spam','drafts') GROUP BY thread_id", array_merge([$uid], $tids)) as $t) {
            $threadCounts[$t['thread_id']] = (int)$t['c'];
        }
    }

    $out = [];
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $folder = $r['folder'];
        $dto = [
            'id' => (string)$id,
            'thread_id' => (string)$r['thread_id'],
            'from' => ['name' => $r['from_name'], 'address' => $r['from_address']],
            'to' => $rcpt[$id]['to'] ?? [],
            'cc' => $rcpt[$id]['cc'] ?? [],
            'bcc' => $folder === 'sent' || $folder === 'drafts' || $folder === 'scheduled' ? ($rcpt[$id]['bcc'] ?? []) : [],
            'subject' => $r['subject'],
            'snippet' => $r['snippet'],
            'date' => db_to_iso($r['received_at']),
            'is_read' => (bool)$r['is_read'],
            'is_starred' => (bool)$r['is_starred'],
            'is_important' => (bool)$r['is_important'],
            'folder' => $folder,
            'label_ids' => $labels[$id] ?? [],
            'attachments' => $atts[$id] ?? [],
            'thread_count' => max(1, $threadCounts[$r['thread_id']] ?? 1),
            'snoozed_until' => db_to_iso($r['snoozed_until']),
            'scheduled_at' => db_to_iso($r['scheduled_at']),
            'is_draft' => (bool)$r['is_draft'],
        ];
        if ($full) {
            $dto['body_html'] = sanitize_html((string)$r['body_html']);
            $dto['body_text'] = (string)$r['body_text'];
        }
        $out[] = $dto;
    }
    return $out;
}

// ---------------------------------------------------------------- storing a message in one mailbox

/**
 * Insert one mailbox copy. $m keys: message_id, in_reply_to, references, from{name,address}, to[], cc[], bcc[],
 * subject, html, text, folder, is_read, received_at, spam_score, size, is_draft, scheduled_at, sent_state.
 */
function mailbox_store(int $userId, array $m): int
{
    $threadId = resolve_thread($userId, (string)$m['subject'], $m['in_reply_to'] ?? null, (string)($m['references'] ?? ''));
    $html = sanitize_html((string)($m['html'] ?? ''));
    $text = (string)($m['text'] ?? '');
    if ($text === '' && $html !== '') {
        $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    $snippet = make_snippet($text, $html);
    $size = (int)($m['size'] ?? (strlen($html) + strlen($text)));
    q('INSERT INTO emails (user_id, thread_id, message_id, in_reply_to, references_hdr, from_name, from_address, subject, snippet, body_html, body_text,
                           folder, is_read, is_draft, size_bytes, spam_score, scheduled_at, sent_state, received_at)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [$userId, $threadId, mb_substr((string)$m['message_id'], 0, 255), ($m['in_reply_to'] ?? null) ?: null, ($m['references'] ?? null) ?: null,
         mb_substr((string)($m['from']['name'] ?? ''), 0, 190), mb_substr((string)$m['from']['address'], 0, 255),
         mb_substr((string)$m['subject'], 0, 998), $snippet, $html, $text,
         $m['folder'] ?? 'inbox', !empty($m['is_read']) ? 1 : 0, !empty($m['is_draft']) ? 1 : 0, $size, $m['spam_score'] ?? 0,
         $m['scheduled_at'] ?? null, $m['sent_state'] ?? 'none', $m['received_at'] ?? now_utc()]);
    $emailId = (int)db()->lastInsertId();
    foreach (['to', 'cc', 'bcc'] as $kind) {
        foreach ($m[$kind] ?? [] as $r) {
            q('INSERT INTO email_recipients (email_id, kind, name, address) VALUES (?,?,?,?)', [$emailId, $kind, $r['name'] ?? '', $r['address']]);
        }
    }
    touch_thread($threadId);
    return $emailId;
}

/** Find a local user by full address (primary address or alias). */
function local_user_by_address(string $address): ?array
{
    return q_one('SELECT u.id, u.username, u.status FROM email_addresses a JOIN users u ON u.id = a.user_id WHERE a.address = ? AND u.status = "active"', [strtolower($address)]);
}

function is_local_address(string $address): bool
{
    return str_ends_with(strtolower($address), '@' . config('domain'));
}

// ---------------------------------------------------------------- sending

/** Fetch attachments the sender uploaded earlier (email_id IS NULL, owned by them). */
function pending_attachments(int $userId, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) {
        return [];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    return q_all("SELECT * FROM attachments WHERE user_id = ? AND id IN ($in) AND email_id IS NULL", array_merge([$userId], $ids));
}

function compose_payload_from_input(): array
{
    $to = parse_recipients(input('to', []), 'to');
    $cc = parse_recipients(input('cc', []), 'cc');
    $bcc = parse_recipients(input('bcc', []), 'bcc');
    $max = (int)setting('max_recipients', '50');
    if (count($to) + count($cc) + count($bcc) > $max) {
        fail('validation', "You can send to at most $max recipients at once.", 422);
    }
    $html = (string)input('body_html', '');
    $text = (string)input('body_text', '');
    if (strlen($html) > 2 * 1024 * 1024 || strlen($text) > 2 * 1024 * 1024) {
        fail('payload_too_large', 'Message body is too large.', 413);
    }
    $att = input('attachment_ids', []);
    return [
        'to' => $to, 'cc' => $cc, 'bcc' => $bcc,
        'subject' => header_safe(mb_substr((string)input('subject', ''), 0, 900)),
        'html' => $html, 'text' => $text,
        'attachment_ids' => is_array($att) ? $att : [],
        'in_reply_to_id' => input_int('in_reply_to', 0),
        'draft_id' => input_int('draft_id', 0),
    ];
}

/**
 * Send (or schedule) a message for $user. Returns the Sent-copy email id.
 *  - local recipients get a mailbox copy immediately (really delivered, inside this system)
 *  - external recipients are queued; they are only marked sent after the SMTP relay accepts them
 */
function email_send(array $user, array $p, ?string $scheduleAt = null): array
{
    $userId = (int)$user['id'];
    $all = array_merge($p['to'], $p['cc'], $p['bcc']);
    if (!$all) {
        fail('validation', 'Add at least one recipient.', 422, ['to' => 'required']);
    }
    $profile = q_one('SELECT display_name FROM user_profiles WHERE user_id = ?', [$userId]);
    $fromAddr = user_address($user['username']);
    $from = ['name' => (string)($profile['display_name'] ?? ''), 'address' => $fromAddr];

    // daily limit (abuse control)
    $sentToday = (int)q_val('SELECT COUNT(*) FROM mail_logs WHERE user_id = ? AND direction IN ("outbound","internal") AND created_at > (UTC_TIMESTAMP() - INTERVAL 1 DAY)', [$userId]);
    if ($sentToday + count($all) > (int)setting('daily_send_limit', '500')) {
        fail('send_limit', 'You have reached the daily sending limit.', 429);
    }

    $atts = pending_attachments($userId, $p['attachment_ids']);
    $attBytes = array_sum(array_map(static fn($a) => (int)$a['size_bytes'], $atts));

    $inReply = null;
    $refs = '';
    if ($p['in_reply_to_id']) {
        $orig = q_one('SELECT message_id, references_hdr FROM emails WHERE id = ? AND user_id = ?', [$p['in_reply_to_id'], $userId]);
        if ($orig) {
            $inReply = $orig['message_id'];
            $refs = trim(($orig['references_hdr'] ?? '') . ' <' . $orig['message_id'] . '>');
        }
    }
    $messageId = new_message_id();
    $scheduled = $scheduleAt !== null;

    $result = db_tx(function () use ($userId, $user, $p, $from, $atts, $inReply, $refs, $messageId, $scheduled, $scheduleAt, $all, $attBytes) {
        // remove the draft this message came from
        if ($p['draft_id']) {
            $old = q_one('SELECT id FROM emails WHERE id = ? AND user_id = ? AND is_draft = 1', [$p['draft_id'], $userId]);
            if ($old) {
                // keep any attachments by detaching them first
                q('UPDATE attachments SET email_id = NULL WHERE email_id = ?', [$old['id']]);
                q('DELETE FROM emails WHERE id = ?', [$old['id']]);
            }
        }
        $sentId = mailbox_store($userId, [
            'message_id' => $messageId, 'in_reply_to' => $inReply, 'references' => $refs, 'from' => $from,
            'to' => $p['to'], 'cc' => $p['cc'], 'bcc' => $p['bcc'], 'subject' => $p['subject'],
            'html' => $p['html'], 'text' => $p['text'], 'folder' => $scheduled ? 'scheduled' : 'sent',
            'is_read' => true, 'scheduled_at' => $scheduleAt, 'sent_state' => $scheduled ? 'queued' : 'queued',
            'size' => strlen($p['html']) + strlen($p['text']),
        ]);
        foreach ($atts as $a) {
            q('UPDATE attachments SET email_id = ? WHERE id = ?', [$sentId, $a['id']]);
        }
        q('UPDATE emails SET has_attachments = ? WHERE id = ?', [$atts ? 1 : 0, $sentId]);
        return $sentId;
    });

    if ($scheduled) {
        return ['id' => (string)$result, 'status' => 'scheduled', 'scheduled_at' => db_to_iso($scheduleAt)];
    }
    return email_dispatch($result) + ['id' => (string)$result];
}

/**
 * Deliver a stored outbound message: local recipients immediately, external recipients via the queue.
 * Safe to call again (used by the scheduled-email cron): already-delivered recipients are skipped.
 */
function email_dispatch(int $sentEmailId): array
{
    $em = q_one('SELECT * FROM emails WHERE id = ?', [$sentEmailId]);
    if (!$em) {
        return ['status' => 'failed'];
    }
    $userId = (int)$em['user_id'];
    $rcpts = q_all('SELECT kind, name, address FROM email_recipients WHERE email_id = ?', [$sentEmailId]);
    $atts = q_all('SELECT * FROM attachments WHERE email_id = ?', [$sentEmailId]);
    $local = $external = [];
    foreach ($rcpts as $r) {
        if (is_local_address($r['address'])) {
            $local[] = $r;
        } else {
            $external[] = $r;
        }
    }

    $deliveredLocal = 0;
    $unknown = [];
    foreach ($local as $r) {
        $dest = local_user_by_address($r['address']);
        if (!$dest) {
            $unknown[] = $r['address'];
            mail_log('internal', $userId, $em['from_address'], $r['address'], $em['subject'], (int)$em['size_bytes'], $em['message_id'], 'no_such_mailbox');
            continue;
        }
        if ((int)q_val('SELECT 1 FROM emails WHERE user_id = ? AND message_id = ? LIMIT 1', [$dest['id'], $em['message_id']])) {
            continue; // already delivered on an earlier attempt
        }
        $copyId = mailbox_store((int)$dest['id'], [
            'message_id' => $em['message_id'], 'in_reply_to' => $em['in_reply_to'], 'references' => $em['references_hdr'] ?? '',
            'from' => ['name' => $em['from_name'], 'address' => $em['from_address']],
            'to' => array_values(array_filter($rcpts, static fn($x) => $x['kind'] === 'to')),
            'cc' => array_values(array_filter($rcpts, static fn($x) => $x['kind'] === 'cc')),
            'subject' => $em['subject'], 'html' => $em['body_html'], 'text' => $em['body_text'],
            'folder' => 'inbox', 'is_read' => false, 'size' => (int)$em['size_bytes'],
        ]);
        foreach ($atts as $a) {
            attachment_clone($a, (int)$dest['id'], $copyId);
        }
        q('UPDATE emails SET has_attachments = ? WHERE id = ?', [$atts ? 1 : 0, $copyId]);
        notify_user((int)$dest['id'], 'new_email', ($em['from_name'] ?: $em['from_address']), $em['subject'] !== '' ? $em['subject'] : '(no subject)', $copyId);
        mail_log('internal', $userId, $em['from_address'], $r['address'], $em['subject'], (int)$em['size_bytes'], $em['message_id'], 'delivered');
        $deliveredLocal++;
    }

    $queued = 0;
    foreach ($external as $r) {
        $exists = q_val('SELECT 1 FROM mail_queue WHERE email_id = ? AND rcpt_address = ?', [$sentEmailId, $r['address']]);
        if (!$exists) {
            q('INSERT INTO mail_queue (email_id, user_id, direction, rcpt_address, status) VALUES (?,?, "outbound", ?, "pending")', [$sentEmailId, $userId, $r['address']]);
            $queued++;
        }
    }

    if ($external) {
        $state = 'queued';
    } else {
        $state = ($deliveredLocal > 0 || !$unknown) ? 'sent' : 'failed';
    }
    q('UPDATE emails SET sent_state = ?, folder = "sent", scheduled_at = NULL, received_at = IF(folder = "scheduled", UTC_TIMESTAMP(), received_at) WHERE id = ?', [$state, $sentEmailId]);

    if ($unknown) {
        $addrs = implode(', ', array_slice($unknown, 0, 5));
        notify_user($userId, 'system', 'Message not delivered', "No A-Mail account exists for: $addrs");
    }
    $status = $external ? (smtp_configured_safe() ? 'queued' : 'queued_no_relay') : ($unknown && !$deliveredLocal ? 'undeliverable' : 'delivered');
    return ['status' => $status, 'delivered_internal' => $deliveredLocal, 'queued_external' => $queued, 'unknown_recipients' => $unknown];
}

function smtp_configured_safe(): bool
{
    require_once dirname(__DIR__) . '/config/mail_config.php';
    return smtp_configured();
}

function mail_log(string $direction, ?int $userId, ?string $from, ?string $to, ?string $subject, ?int $size, ?string $mid, string $result, ?string $detail = null): void
{
    q('INSERT INTO mail_logs (direction, user_id, from_address, to_address, subject_len, size_bytes, message_id, result, detail) VALUES (?,?,?,?,?,?,?,?,?)',
        [$direction, $userId, $from, $to, $subject !== null ? mb_strlen($subject) : null, $size, $mid, mb_substr($result, 0, 40), $detail ? mb_substr($detail, 0, 500) : null]);
}

// ---------------------------------------------------------------- inbound (from MTA pipe / IMAP poll)

/**
 * Accept a raw RFC 822 message addressed to local recipients. Returns per-recipient results.
 * Called by mail/inbound.php (MTA pipe or HTTP hook) and the IMAP poller. Records only metadata in logs, never bodies.
 */
function inbound_deliver_raw(string $raw, array $envelopeRcpts = []): array
{
    $p = parse_raw_mail($raw);
    $auth = inbound_auth_signals($p['headers']);
    $spam = spam_score($p, $auth);

    // Sender block-list managed in Admin > Spam: blocked senders are always filed as spam.
    $blocked = array_filter(array_map('trim', explode("\n", strtolower(setting('blocked_senders', '')))));
    if ($blocked && in_array(strtolower((string)($p['from']['address'] ?? '')), $blocked, true)) {
        $spam['is_spam'] = true;
        $spam['score'] = max($spam['score'], 10.0);
        $spam['reasons'][] = 'sender blocked by administrator';
    }

    $targets = $envelopeRcpts;
    if (!$targets) {
        foreach (array_merge($p['to'], $p['cc']) as $r) {
            $targets[] = $r['address'];
        }
    }
    $targets = array_values(array_unique(array_map('strtolower', array_filter($targets, 'is_local_address'))));
    $results = [];
    foreach ($targets as $addr) {
        $dest = local_user_by_address($addr);
        if (!$dest) {
            mail_log('inbound', null, $p['from']['address'], $addr, $p['subject'], $p['size'], $p['message_id'], 'no_such_mailbox');
            $results[$addr] = 'no_such_mailbox';
            continue;
        }
        $uid = (int)$dest['id'];
        $mid = $p['message_id'] !== '' ? $p['message_id'] : new_message_id();
        if ((int)q_val('SELECT 1 FROM emails WHERE user_id = ? AND message_id = ? LIMIT 1', [$uid, $mid])) {
            $results[$addr] = 'duplicate';
            continue;
        }
        $blob = array_sum(array_map(static fn($a) => strlen($a['data']), $p['attachments']));
        if (user_used_bytes($uid) + $p['size'] + $blob > user_quota_bytes($uid)) {
            mail_log('inbound', $uid, $p['from']['address'], $addr, $p['subject'], $p['size'], $mid, 'rejected_quota');
            $results[$addr] = 'quota_exceeded';
            continue;
        }
        $folder = $spam['is_spam'] ? 'spam' : 'inbox';
        $id = db_tx(function () use ($uid, $p, $mid, $folder, $spam, $addr) {
            $to = $p['to'] ?: [['name' => '', 'address' => $addr]];
            $emailId = mailbox_store($uid, [
                'message_id' => $mid, 'in_reply_to' => $p['in_reply_to'] ?: null, 'references' => $p['references'],
                'from' => $p['from'], 'to' => $to, 'cc' => $p['cc'], 'subject' => $p['subject'],
                'html' => $p['html'], 'text' => $p['text'], 'folder' => $folder, 'is_read' => false,
                'received_at' => $p['date'] ?? now_utc(), 'spam_score' => $spam['score'], 'size' => $p['size'],
            ]);
            $n = 0;
            foreach ($p['attachments'] as $a) {
                if (attachment_save_blob($uid, $emailId, $a['file_name'], $a['mime_type'], $a['data'])) {
                    $n++;
                }
            }
            if ($n) {
                q('UPDATE emails SET has_attachments = 1 WHERE id = ?', [$emailId]);
            }
            return $emailId;
        });
        if ($folder === 'inbox') {
            notify_user($uid, 'new_email', ($p['from']['name'] ?: $p['from']['address']), $p['subject'] !== '' ? $p['subject'] : '(no subject)', $id);
        }
        mail_log('inbound', $uid, $p['from']['address'], $addr, $p['subject'], $p['size'], $mid, $folder === 'spam' ? 'spam' : 'delivered', $spam['reasons'] ? implode('; ', $spam['reasons']) : null);
        $results[$addr] = $folder === 'spam' ? 'spam' : 'delivered';
    }
    return $results;
}

// ---------------------------------------------------------------- listing / actions

const FOLDER_WHERE = [
    'inbox'     => "e.folder = 'inbox' AND (e.snoozed_until IS NULL OR e.snoozed_until <= UTC_TIMESTAMP())",
    'sent'      => "e.folder = 'sent'",
    'drafts'    => "e.folder = 'drafts'",
    'spam'      => "e.folder = 'spam'",
    'trash'     => "e.folder = 'trash'",
    'archive'   => "e.folder = 'archive'",
    'scheduled' => "e.folder = 'scheduled'",
    'starred'   => "e.is_starred = 1 AND e.folder NOT IN ('trash','spam')",
    'important' => "e.is_important = 1 AND e.folder NOT IN ('trash','spam')",
    'snoozed'   => "e.snoozed_until IS NOT NULL AND e.snoozed_until > UTC_TIMESTAMP() AND e.folder NOT IN ('trash','spam')",
    'all'       => "e.folder NOT IN ('trash','spam')",
];

function paging(): array
{
    $page = max(1, input_int('page', 1));
    $per = min(MAX_PER_PAGE, max(1, input_int('per_page', DEFAULT_PER_PAGE)));
    return [$page, $per, ($page - 1) * $per];
}

function email_list(int $userId, string $folderKey): never
{
    [$page, $per, $off] = paging();
    $where = FOLDER_WHERE[$folderKey] ?? FOLDER_WHERE['inbox'];
    if (input_int('snoozed', 0) === 1) {
        $where = FOLDER_WHERE['snoozed'];
    }
    $join = '';
    $args = [$userId];
    if (($label = input_int('label_id', 0)) > 0) {
        // join order in SQL is: label placeholder first, then the user placeholder in WHERE
        $join = ' JOIN email_labels el ON el.email_id = e.id AND el.label_id = ? JOIN labels lb ON lb.id = el.label_id AND lb.user_id = e.user_id ';
        $args = [$label, $userId];
    }
    $base = "FROM emails e $join WHERE e.user_id = ? AND ($where)";
    $total = (int)q_val("SELECT COUNT(*) $base", $args);
    $rows = q_all("SELECT e.* $base ORDER BY e.received_at DESC, e.id DESC LIMIT $per OFFSET $off", $args);
    paged(email_dtos($rows), $page, $per, $total);
}

/** Verify every id belongs to $userId; returns the owned ids only. */
function owned_email_ids(int $userId, array $ids): array
{
    if (!$ids) {
        return [];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    return array_map('intval', array_column(q_all("SELECT id FROM emails WHERE user_id = ? AND id IN ($in)", array_merge([$userId], $ids)), 'id'));
}

function emails_move(int $userId, array $ids, string $folder): int
{
    $ids = owned_email_ids($userId, $ids);
    if (!$ids) {
        return 0;
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $trashed = $folder === 'trash' ? 'UTC_TIMESTAMP()' : 'NULL';
    $st = q("UPDATE emails SET prev_folder = IF(folder <> ?, folder, prev_folder), folder = ?, trashed_at = $trashed, snoozed_until = NULL WHERE user_id = ? AND id IN ($in)",
        array_merge([$folder, $folder, $userId], $ids));
    return $st->rowCount();
}

function emails_delete_forever(int $userId, array $ids): int
{
    $ids = owned_email_ids($userId, $ids);
    if (!$ids) {
        return 0;
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    foreach (q_all("SELECT storage_path FROM attachments WHERE email_id IN ($in)", $ids) as $f) {
        delete_attachment_file($f['storage_path']);
    }
    $threads = array_column(q_all("SELECT DISTINCT thread_id FROM emails WHERE id IN ($in)", $ids), 'thread_id');
    $n = q("DELETE FROM emails WHERE user_id = ? AND id IN ($in)", array_merge([$userId], $ids))->rowCount();
    foreach ($threads as $t) {
        if ((int)q_val('SELECT COUNT(*) FROM emails WHERE thread_id = ?', [$t]) === 0) {
            q('DELETE FROM email_threads WHERE id = ?', [$t]);
        } else {
            touch_thread((int)$t);
        }
    }
    return $n;
}

function emails_set_flag(int $userId, array $ids, string $col, bool $value): int
{
    if (!in_array($col, ['is_read', 'is_starred', 'is_important'], true)) {
        return 0;
    }
    $ids = owned_email_ids($userId, $ids);
    if (!$ids) {
        return 0;
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    return q("UPDATE emails SET $col = ? WHERE user_id = ? AND id IN ($in)", array_merge([$value ? 1 : 0, $userId], $ids))->rowCount();
}
