<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';

function notification_prefs(int $userId): array
{
    $row = q_val('SELECT settings FROM user_profiles WHERE user_id = ?', [$userId]);
    $s = $row ? (json_decode((string)$row, true) ?: []) : [];
    $p = $s['notifications'] ?? [];
    return ['new_mail' => (bool)($p['new_mail'] ?? true), 'sound' => (bool)($p['sound'] ?? true), 'security' => (bool)($p['security'] ?? true)];
}

/** Create an in-app notification. Push delivery happens in cron/process-outgoing via push_pending(). */
function notify_user(int $userId, string $type, string $title, string $body = '', ?int $emailId = null): void
{
    $prefs = notification_prefs($userId);
    if (($type === 'new_email' && !$prefs['new_mail']) || ($type === 'security' && !$prefs['security'])) {
        return;
    }
    q('INSERT INTO notifications (user_id, type, title, body, email_id) VALUES (?,?,?,?,?)',
        [$userId, $type, mb_substr($title, 0, 160), mb_substr($body, 0, 500), $emailId]);
}

function notification_dto(array $n): array
{
    return [
        'id' => (string)$n['id'],
        'title' => $n['title'],
        'body' => $n['body'],
        'type' => $n['type'],
        'email_id' => $n['email_id'] !== null ? (string)$n['email_id'] : null,
        'created_at' => db_to_iso($n['created_at']),
        'read' => (bool)$n['is_read'],
    ];
}
