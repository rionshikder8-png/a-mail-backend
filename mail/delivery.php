<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/services/email_service.php';
require_once __DIR__ . '/outbound.php';
require_once __DIR__ . '/smtp.php';

const MAX_DELIVERY_ATTEMPTS = 6;

/** Exponential back-off in minutes after attempt N. */
function retry_delay_minutes(int $attempts): int
{
    return [5, 15, 60, 180, 720, 1440][min($attempts, 5)];
}

/**
 * Process due outbound queue rows. Each recipient is delivered individually so one bad address
 * never blocks the rest. Honest states: an email is only 'sent' after the relay accepted every recipient.
 * @return array{processed:int,sent:int,deferred:int,failed:int}
 */
function process_outbound_queue(int $limit = 50): array
{
    $stats = ['processed' => 0, 'sent' => 0, 'deferred' => 0, 'failed' => 0];
    if (!smtp_configured()) {
        return $stats + ['note' => 'SMTP relay not configured; queue left untouched'];
    }
    $rows = q_all('SELECT * FROM mail_queue WHERE direction = "outbound" AND status IN ("pending","deferred") AND next_attempt_at <= UTC_TIMESTAMP() ORDER BY id LIMIT ' . (int)$limit);
    foreach ($rows as $row) {
        // claim the row so two workers never send the same message
        $claimed = q('UPDATE mail_queue SET status = "sending", attempts = attempts + 1 WHERE id = ? AND status IN ("pending","deferred")', [$row['id']])->rowCount();
        if (!$claimed) {
            continue;
        }
        $stats['processed']++;
        $email = q_one('SELECT * FROM emails WHERE id = ?', [$row['email_id']]);
        if (!$email) {
            q('UPDATE mail_queue SET status = "failed", last_error = "message removed" WHERE id = ?', [$row['id']]);
            $stats['failed']++;
            continue;
        }
        $rcpts = q_all('SELECT kind, name, address FROM email_recipients WHERE email_id = ?', [$email['id']]);
        $atts = q_all('SELECT * FROM attachments WHERE email_id = ?', [$email['id']]);
        $raw = build_raw_message($email, $rcpts, $atts);
        $res = smtp_send($email['from_address'], [$row['rcpt_address']], $raw);
        $attempts = (int)$row['attempts'] + 1;

        if ($res['ok']) {
            q('UPDATE mail_queue SET status = "sent", last_error = NULL WHERE id = ?', [$row['id']]);
            q('INSERT INTO delivery_logs (queue_id, rcpt_address, status, smtp_code, response) VALUES (?,?, "delivered", ?, ?)', [$row['id'], $row['rcpt_address'], $res['code'], $res['response']]);
            mail_log('outbound', (int)$email['user_id'], $email['from_address'], $row['rcpt_address'], $email['subject'], (int)$email['size_bytes'], $email['message_id'], 'delivered', $res['code']);
            $stats['sent']++;
        } else {
            $permanent = preg_match('/^5\d\d$/', $res['code']) === 1;
            if ($permanent || $attempts >= MAX_DELIVERY_ATTEMPTS) {
                q('UPDATE mail_queue SET status = "failed", last_error = ? WHERE id = ?', [$res['response'], $row['id']]);
                q('INSERT INTO delivery_logs (queue_id, rcpt_address, status, smtp_code, response) VALUES (?,?, "failed", ?, ?)', [$row['id'], $row['rcpt_address'], $res['code'], $res['response']]);
                mail_log('outbound', (int)$email['user_id'], $email['from_address'], $row['rcpt_address'], $email['subject'], (int)$email['size_bytes'], $email['message_id'], 'failed', $res['code']);
                notify_user((int)$email['user_id'], 'system', 'Message could not be delivered', 'To ' . $row['rcpt_address'] . ': ' . mb_substr($res['response'], 0, 200));
                $stats['failed']++;
            } else {
                $next = gmdate('Y-m-d H:i:s', time() + retry_delay_minutes($attempts) * 60);
                q('UPDATE mail_queue SET status = "deferred", last_error = ?, next_attempt_at = ? WHERE id = ?', [$res['response'], $next, $row['id']]);
                q('INSERT INTO delivery_logs (queue_id, rcpt_address, status, smtp_code, response) VALUES (?,?, "deferred", ?, ?)', [$row['id'], $row['rcpt_address'], $res['code'], $res['response']]);
                $stats['deferred']++;
            }
        }
        refresh_sent_state((int)$email['id']);
    }
    return $stats;
}

/** Roll the per-recipient queue states up into the message's sent_state. */
function refresh_sent_state(int $emailId): void
{
    $rows = q_all('SELECT status, COUNT(*) c FROM mail_queue WHERE email_id = ? GROUP BY status', [$emailId]);
    $by = array_column($rows, 'c', 'status');
    if (!$by) {
        return;
    }
    $open = ($by['pending'] ?? 0) + ($by['deferred'] ?? 0) + ($by['sending'] ?? 0);
    $state = $open > 0 ? 'queued' : (($by['sent'] ?? 0) > 0 ? 'sent' : 'failed');
    q('UPDATE emails SET sent_state = ? WHERE id = ?', [$state, $emailId]);
}
