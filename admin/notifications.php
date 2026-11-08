<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/notification_helpers.php';
$admin = admin_guard('notifications.send');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_only();
    $subject = trim((string)($_POST['title'] ?? ''));
    $body = trim((string)($_POST['body'] ?? ''));
    $aud = in_array($_POST['audience'] ?? '', ['all', 'plan_free', 'plan_paid'], true) ? $_POST['audience'] : 'all';
    if ($subject === '' || $body === '' || mb_strlen($subject) > 160 || mb_strlen($body) > 500) {
        flash('err', 'Enter a title (up to 160 characters) and a message (up to 500).');
    } else {
        q('INSERT INTO broadcasts (admin_id, title, body, audience) VALUES (?,?,?,?)', [$admin['id'], $subject, $body, $aud]);
        admin_log('broadcast.create', 'broadcast', (int)db()->lastInsertId(), ['audience' => $aud]);
        flash('ok', 'Announcement queued. It is delivered to users within a few minutes by the scheduled job.');
    }
    redirect('/admin/notifications.php');
}

$rows = q_all('SELECT b.*, a.name AS admin_name FROM broadcasts b LEFT JOIN admins a ON a.id = b.admin_id ORDER BY b.id DESC LIMIT 30');
$devices = (int)q_val('SELECT COUNT(*) FROM devices WHERE push_token IS NOT NULL AND revoked_at IS NULL');
$title = 'Notifications';
require __DIR__ . '/partials/header.php';
?>
<?php if (!fcm_configured()): ?><div class="alert note">Push delivery is not configured (<?= $devices ?> devices have registered tokens). Announcements appear in each user's in-app notification list. Add the FCM service account to the server environment to also send push notifications.</div><?php endif; ?>
<div class="grid cols-2">
  <div class="card"><div class="hd"><h2>New announcement</h2></div><form method="post" class="bd"><?= csrf_field() ?>
    <div class="form-row"><label for="t">Title</label><input id="t" type="text" name="title" maxlength="160" required></div>
    <div class="form-row"><label for="b">Message</label><textarea id="b" name="body" maxlength="500" required></textarea></div>
    <div class="form-row"><label for="a">Audience</label><select id="a" name="audience"><option value="all">All active users</option><option value="plan_free">Free plan</option><option value="plan_paid">Paid plans</option></select></div>
    <button class="btn primary" type="submit" data-confirm="Send this announcement to the selected users?">Send announcement</button></form></div>
  <div class="card"><div class="hd"><h2>Recent announcements</h2></div>
  <?php if (!$rows): ?><div class="empty">Nothing sent yet.</div><?php else: ?><div class="tablewrap"><table><tbody><?php foreach ($rows as $r): ?>
    <tr><td><b><?= e($r['title']) ?></b><div class="muted"><?= e(mb_substr($r['body'], 0, 100)) ?></div></td><td><?= status_badge($r['status']) ?><div class="muted"><?= $r['status'] === 'sent' ? number_format((int)$r['recipients']) . ' users' : e($r['audience']) ?></div></td><td class="muted"><?= e(ago($r['created_at'])) ?></td></tr>
  <?php endforeach; ?></tbody></table></div><?php endif; ?></div>
</div>
<?php require __DIR__ . '/partials/footer.php';
