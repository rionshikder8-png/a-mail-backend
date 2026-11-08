<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/services/storage_service.php';
admin_guard('dashboard.view');

$c = q_one('SELECT
    (SELECT COUNT(*) FROM users WHERE status <> "deleted") AS users,
    (SELECT COUNT(*) FROM users WHERE status = "active") AS active,
    (SELECT COUNT(*) FROM users WHERE status = "suspended") AS suspended,
    (SELECT COUNT(*) FROM users WHERE created_at > (UTC_TIMESTAMP() - INTERVAL 7 DAY)) AS new7,
    (SELECT COUNT(*) FROM users WHERE last_login_at > (UTC_TIMESTAMP() - INTERVAL 1 DAY)) AS dau,
    (SELECT COUNT(*) FROM mail_logs WHERE created_at > (UTC_TIMESTAMP() - INTERVAL 1 DAY) AND direction IN ("outbound","internal")) AS sent24,
    (SELECT COUNT(*) FROM mail_logs WHERE created_at > (UTC_TIMESTAMP() - INTERVAL 1 DAY) AND direction = "inbound") AS recv24,
    (SELECT COUNT(*) FROM mail_queue WHERE status IN ("pending","deferred")) AS queued,
    (SELECT COUNT(*) FROM mail_queue WHERE status = "failed") AS qfailed,
    (SELECT COUNT(*) FROM abuse_reports WHERE status IN ("open","investigating")) AS abuse,
    (SELECT COUNT(*) FROM support_tickets WHERE status <> "closed") AS tickets,
    (SELECT COALESCE(SUM(size_bytes),0) FROM attachments) AS att_bytes,
    (SELECT COALESCE(SUM(size_bytes),0) FROM emails) AS mail_bytes');

// signups per day, last 14 days
$days = [];
for ($i = 13; $i >= 0; $i--) {
    $days[gmdate('Y-m-d', time() - $i * 86400)] = 0;
}
foreach (q_all('SELECT DATE(created_at) d, COUNT(*) n FROM users WHERE created_at > (UTC_TIMESTAMP() - INTERVAL 14 DAY) GROUP BY DATE(created_at)') as $r) {
    if (isset($days[$r['d']])) {
        $days[$r['d']] = (int)$r['n'];
    }
}
$max = max(1, max($days));
$mailDays = array_fill_keys(array_keys($days), 0);
foreach (q_all('SELECT DATE(created_at) d, COUNT(*) n FROM mail_logs WHERE created_at > (UTC_TIMESTAMP() - INTERVAL 14 DAY) GROUP BY DATE(created_at)') as $r) {
    if (isset($mailDays[$r['d']])) {
        $mailDays[$r['d']] = (int)$r['n'];
    }
}
$mmax = max(1, max($mailDays));

$recent = q_all('SELECT u.id, u.username, u.status, u.created_at, p.display_name FROM users u JOIN user_profiles p ON p.user_id = u.id ORDER BY u.id DESC LIMIT 6');
$activity = q_all('SELECT l.action, l.target_type, l.target_id, l.created_at, a.name FROM admin_activity_logs l LEFT JOIN admins a ON a.id = l.admin_id ORDER BY l.id DESC LIMIT 6');

$title = 'Dashboard';
require __DIR__ . '/partials/header.php';
$empty = (int)$c['users'] === 0;
?>
<?php if ($empty): ?>
<div class="alert note">No accounts exist yet. Statistics will appear once people register through the app.</div>
<?php endif; ?>
<div class="grid cols-4">
  <div class="card stat"><div class="k">Total accounts</div><div class="v"><?= number_format((int)$c['users']) ?></div><div class="s"><?= (int)$c['new7'] ?> new in 7 days</div></div>
  <div class="card stat"><div class="k">Signed in (24 h)</div><div class="v"><?= number_format((int)$c['dau']) ?></div><div class="s"><?= (int)$c['suspended'] ?> suspended</div></div>
  <div class="card stat"><div class="k">Mail sent (24 h)</div><div class="v"><?= number_format((int)$c['sent24']) ?></div><div class="s"><?= number_format((int)$c['recv24']) ?> received</div></div>
  <div class="card stat"><div class="k">Outbound queue</div><div class="v"><?= number_format((int)$c['queued']) ?></div><div class="s"><?= (int)$c['qfailed'] ?> failed</div></div>
</div>
<div class="grid cols-2" style="margin-top:16px">
  <div class="card"><div class="hd"><h2>New accounts (14 days)</h2></div><div class="bd">
    <div class="bars" role="img" aria-label="New accounts per day"><?php foreach ($days as $d => $n): ?><div style="height:<?= max(2, (int)round($n / $max * 100)) ?>%" title="<?= e($d) ?>: <?= $n ?>"></div><?php endforeach; ?></div>
    <div class="bars-x"><?php foreach (array_keys($days) as $d): ?><span><?= e(gmdate('j', strtotime($d))) ?></span><?php endforeach; ?></div>
  </div></div>
  <div class="card"><div class="hd"><h2>Mail volume (14 days)</h2></div><div class="bd">
    <div class="bars" role="img" aria-label="Messages per day"><?php foreach ($mailDays as $d => $n): ?><div style="height:<?= max(2, (int)round($n / $mmax * 100)) ?>%" title="<?= e($d) ?>: <?= $n ?>"></div><?php endforeach; ?></div>
    <div class="bars-x"><?php foreach (array_keys($mailDays) as $d): ?><span><?= e(gmdate('j', strtotime($d))) ?></span><?php endforeach; ?></div>
  </div></div>
</div>
<div class="grid cols-3" style="margin-top:16px">
  <div class="card stat"><div class="k">Open abuse reports</div><div class="v"><?= (int)$c['abuse'] ?></div><div class="s"><a href="/admin/abuse-reports.php">Review</a></div></div>
  <div class="card stat"><div class="k">Open support tickets</div><div class="v"><?= (int)$c['tickets'] ?></div><div class="s"><a href="/admin/support.php">Open inbox</a></div></div>
  <div class="card stat"><div class="k">Storage in use</div><div class="v"><?= e(format_bytes((int)$c['att_bytes'] + (int)$c['mail_bytes'])) ?></div><div class="s">Mail <?= e(format_bytes((int)$c['mail_bytes'])) ?> · Files <?= e(format_bytes((int)$c['att_bytes'])) ?></div></div>
</div>
<div class="grid cols-2" style="margin-top:16px">
  <div class="card"><div class="hd"><h2>Newest accounts</h2><a href="/admin/users.php">All users</a></div>
    <?php if (!$recent): ?><div class="empty">No accounts yet.</div><?php else: ?>
    <div class="tablewrap"><table><tbody><?php foreach ($recent as $u): ?>
      <tr><td><a href="/admin/user-details.php?id=<?= (int)$u['id'] ?>"><?= e($u['username']) ?>@<?= e(config('domain')) ?></a><div class="muted"><?= e($u['display_name']) ?></div></td><td><?= status_badge($u['status']) ?></td><td class="muted"><?= e(ago($u['created_at'])) ?></td></tr>
    <?php endforeach; ?></tbody></table></div><?php endif; ?></div>
  <div class="card"><div class="hd"><h2>Recent admin activity</h2></div>
    <?php if (!$activity): ?><div class="empty">No activity recorded yet.</div><?php else: ?>
    <div class="tablewrap"><table><tbody><?php foreach ($activity as $a): ?>
      <tr><td><b><?= e($a['action']) ?></b><div class="muted"><?= e($a['name'] ?? 'system') ?><?= $a['target_type'] ? ' · ' . e($a['target_type']) . ' #' . (int)$a['target_id'] : '' ?></div></td><td class="muted"><?= e(ago($a['created_at'])) ?></td></tr>
    <?php endforeach; ?></tbody></table></div><?php endif; ?></div>
</div>
<?php require __DIR__ . '/partials/footer.php';
