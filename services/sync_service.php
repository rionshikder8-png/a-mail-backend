<?php
declare(strict_types=1);

require_once __DIR__ . '/email_service.php';

/** Release snoozed mail that is now due: it reappears in the inbox as unread and notifies the user. */
function sync_wake_snoozed(): int
{
    $rows = q_all('SELECT id, user_id, from_name, from_address, subject FROM emails WHERE snoozed_until IS NOT NULL AND snoozed_until <= UTC_TIMESTAMP() LIMIT 500');
    foreach ($rows as $r) {
        q('UPDATE emails SET snoozed_until = NULL, is_read = 0, received_at = UTC_TIMESTAMP() WHERE id = ?', [$r['id']]);
        notify_user((int)$r['user_id'], 'new_email', 'Snoozed: ' . ($r['from_name'] ?: $r['from_address']), $r['subject'] ?: '(no subject)', (int)$r['id']);
    }
    return count($rows);
}

/** Send scheduled messages that are due. Each is claimed atomically so overlapping cron runs cannot double-send. */
function sync_send_scheduled(int $limit = 100): int
{
    $rows = q_all('SELECT id FROM emails WHERE folder = "scheduled" AND scheduled_at IS NOT NULL AND scheduled_at <= UTC_TIMESTAMP() ORDER BY scheduled_at LIMIT ' . (int)$limit);
    $n = 0;
    foreach ($rows as $r) {
        $claimed = q('UPDATE emails SET folder = "sent", sent_state = "queued" WHERE id = ? AND folder = "scheduled"', [$r['id']])->rowCount();
        if ($claimed) {
            email_dispatch((int)$r['id']);
            $n++;
        }
    }
    return $n;
}

/** Permanently delete trash/spam older than the retention window. */
function sync_purge_old(): array
{
    $trashDays = max(1, (int)setting('trash_retention_days', '30'));
    $spamDays = max(1, (int)setting('spam_retention_days', '30'));
    $out = ['trash' => 0, 'spam' => 0];
    foreach (['trash' => $trashDays, 'spam' => $spamDays] as $folder => $days) {
        $col = $folder === 'trash' ? 'trashed_at' : 'updated_at';
        $rows = q_all("SELECT id, user_id FROM emails WHERE folder = ? AND $col < (UTC_TIMESTAMP() - INTERVAL $days DAY) LIMIT 1000", [$folder]);
        $byUser = [];
        foreach ($rows as $r) {
            $byUser[$r['user_id']][] = (int)$r['id'];
        }
        foreach ($byUser as $uid => $ids) {
            $out[$folder] += emails_delete_forever((int)$uid, $ids);
        }
    }
    return $out;
}

/** Remove uploads that were never attached to a message within 24 h. */
function sync_clean_orphan_uploads(): int
{
    $rows = q_all('SELECT id, storage_path FROM attachments WHERE email_id IS NULL AND created_at < (UTC_TIMESTAMP() - INTERVAL 1 DAY) LIMIT 1000');
    foreach ($rows as $r) {
        delete_attachment_file($r['storage_path']);
        q('DELETE FROM attachments WHERE id = ?', [$r['id']]);
    }
    // stray temp files
    $n = count($rows);
    foreach (glob(storage_path('temporary') . '/*') ?: [] as $f) {
        if (is_file($f) && filemtime($f) < time() - 3600) {
            @unlink($f);
            $n++;
        }
    }
    return $n;
}

/** Deliver admin broadcasts into user notifications. */
function sync_broadcasts(): int
{
    $sent = 0;
    foreach (q_all('SELECT * FROM broadcasts WHERE status = "pending" ORDER BY id LIMIT 5') as $b) {
        $where = match ($b['audience']) {
            'plan_free' => 'AND (sp.code = "free" OR sp.code IS NULL)',
            'plan_paid' => 'AND sp.code IS NOT NULL AND sp.code <> "free"',
            default => '',
        };
        $users = q_all("SELECT u.id FROM users u LEFT JOIN subscription_plans sp ON sp.id = u.plan_id WHERE u.status = 'active' $where");
        foreach ($users as $u) {
            q('INSERT INTO notifications (user_id, type, title, body) VALUES (?, "system", ?, ?)', [$u['id'], $b['title'], $b['body']]);
        }
        q('UPDATE broadcasts SET status = "sent", recipients = ?, sent_at = UTC_TIMESTAMP() WHERE id = ?', [count($users), $b['id']]);
        $sent++;
    }
    return $sent;
}
