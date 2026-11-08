<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/upload_helpers.php';

/** Recompute and persist one user's usage breakdown. */
function storage_recompute(int $userId): array
{
    $mail = (int)q_val("SELECT COALESCE(SUM(size_bytes),0) FROM emails WHERE user_id = ? AND folder <> 'trash'", [$userId]);
    $trash = (int)q_val("SELECT COALESCE(SUM(size_bytes),0) FROM emails WHERE user_id = ? AND folder = 'trash'", [$userId]);
    $att = (int)q_val('SELECT COALESCE(SUM(size_bytes),0) FROM attachments WHERE user_id = ?', [$userId]);
    q('INSERT INTO storage_usage (user_id, mail_bytes, attachments_bytes, trash_bytes, computed_at) VALUES (?,?,?,?,UTC_TIMESTAMP())
       ON DUPLICATE KEY UPDATE mail_bytes = VALUES(mail_bytes), attachments_bytes = VALUES(attachments_bytes), trash_bytes = VALUES(trash_bytes), computed_at = UTC_TIMESTAMP()',
        [$userId, $mail, $att, $trash]);
    return ['mail_bytes' => $mail, 'attachments_bytes' => $att, 'trash_bytes' => $trash];
}

function storage_dto(int $userId): array
{
    $u = storage_recompute($userId);
    return [
        'used_bytes' => $u['mail_bytes'] + $u['attachments_bytes'] + $u['trash_bytes'],
        'quota_bytes' => user_quota_bytes($userId),
        'attachments_bytes' => $u['attachments_bytes'],
        'mail_bytes' => $u['mail_bytes'],
        'trash_bytes' => $u['trash_bytes'],
    ];
}
