<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
admin_guard('security.view');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_only();
    admin_require('users.suspend');
    if (($_POST['action'] ?? '') === 'unlock') {
        $uid = (int)($_POST['user_id'] ?? 0);
        q('UPDATE users SET failed_logins = 0, locked_until = NULL WHERE id = ?', [$uid]);
        admin_log('user.unlock', 'user', $uid);
        flash('ok', 'Account unlocked.');
    }
    redirect('/admin/security-center.php');
}

$s = q_one('SELECT
   (SELECT COUNT(*) FROM login_history WHERE success = 0 AND created_at > (UTC_TIMESTAMP() - INTERVAL 1 DAY)) AS failed24,
   (SELECT COUNT(*) FROM login_history WHERE success = 1 AND created_at > (UTC_TIMESTAMP() - INTERVAL 1 DAY)) AS ok24,
   (SELECT COUNT(*) FROM users WHERE locked_until IS NOT NULL AND locked_until > UTC_TIMESTAMP()) AS locked,
   (SELECT COUNT(*) FROM security_logs WHERE severity = "critical" AND created_at > (UTC_TIMESTAMP() - INTERVAL 7 DAY)) AS critical7,
   (SELECT COUNT(*) FROM admins WHERE status = "active") AS admins');
$ips = q_all('SELECT ip, COUNT(*) n FROM login_history WHERE success = 0 AND ip IS NOT NULL AND created_at > (UTC_TIMESTAMP() - INTERVAL 1 DAY) GROUP BY ip HAVING n >= 5 ORDER BY n DESC LIMIT 10');
$locked = q_all('SELECT id, username, locked_until, failed_logins FROM users WHERE locked_until IS NOT NULL AND locked_until > UTC_TIMESTAMP() ORDER BY locked_until DESC LIMIT 20');
$events = q_all('SELECT l.*, u.username FROM security_logs l LEFT JOIN users u ON u.id = l.user_id ORDER BY l.id DESC LIMIT 40');
$adminLog = q_all('SELECT l.action, l.target_type, l.target_id, l.ip, l.created_at, a.name FROM admin_activity_logs l LEFT JOIN admins a ON a.id = l.admin_id ORDER BY l.id DESC LIMIT 25');
$title = 'Security center';
require __DIR__ . '/partials/header.php';
?>
<div class="grid cols-4">
  <div class="card stat"><div class="k">Failed sign-ins (24 h)</div><div class="v"><?= (int)$s['failed24'] ?></div><div class="s"><?= (int)$s['ok24'] ?> successful</div></div>
  <div class="card stat"><div class="k">Locked accounts</div><div class="v"><?= (int)$s['locked'] ?></div></div>
  <div class="card stat"><div class="k">Critical events (7 d)</div><div class="v"><?= (int)$s['critical7'] ?></div></div>
  <div class="card stat"><div class="k">Active admins</div><div class="v"><?= (int)$s['admins'] ?></div></div>
</div>
<div class="grid cols-2" style="margin-top:16px">
  <div class="card"><div class="hd"><h2>Possible brute-force sources (24 h)</h2></div>
  <?php if (!$ips): ?><div class="empty">No address has 5 or more failed sign-ins.</div><?php else: ?><table><tbody><?php foreach ($ips as $i): ?><tr><td class="mono"><?= e($i['ip']) ?></td><td style="text-align:right"><?= (int)$i['n'] ?> failures</td></tr><?php endforeach; ?></tbody></table><?php endif; ?></div>
  <div class="card"><div class="hd"><h2>Locked accounts</h2></div>
  <?php if (!$locked): ?><div class="empty">No accounts are locked.</div><?php else: ?><table><tbody><?php foreach ($locked as $l): ?><tr><td><a href="/admin/user-details.php?id=<?= (int)$l['id'] ?>"><?= e($l['username']) ?>@<?= e(config('domain')) ?></a><div class="muted">Until <?= e(dt($l['locked_until'])) ?></div></td>
    <td style="text-align:right"><?php if (admin_can('users.suspend')): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="unlock"><input type="hidden" name="user_id" value="<?= (int)$l['id'] ?>"><button class="btn sm" type="submit">Unlock</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></div>
</div>
<div class="card" style="margin-top:16px"><div class="hd"><h2>Security events</h2></div>
<?php if (!$events): ?><div class="empty">No security events recorded.</div><?php else: ?><div class="tablewrap"><table><thead><tr><th>Time</th><th>Event</th><th>Severity</th><th>Account</th><th>IP</th></tr></thead><tbody>
<?php foreach ($events as $ev): ?><tr><td class="muted" style="white-space:nowrap"><?= e(ago($ev['created_at'])) ?></td><td><?= e($ev['event']) ?></td><td><?= status_badge($ev['severity']) ?></td><td><?= e($ev['username'] ? $ev['username'] . '@' . config('domain') : '—') ?></td><td class="mono"><?= e($ev['ip'] ?? '') ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>
<div class="card" style="margin-top:16px"><div class="hd"><h2>Administrator activity</h2></div>
<?php if (!$adminLog): ?><div class="empty">No administrator activity yet.</div><?php else: ?><div class="tablewrap"><table><thead><tr><th>Time</th><th>Admin</th><th>Action</th><th>Target</th><th>IP</th></tr></thead><tbody>
<?php foreach ($adminLog as $a): ?><tr><td class="muted"><?= e(ago($a['created_at'])) ?></td><td><?= e($a['name'] ?? 'system') ?></td><td><?= e($a['action']) ?></td><td class="muted"><?= $a['target_type'] ? e($a['target_type']) . ' #' . (int)$a['target_id'] : '—' ?></td><td class="mono"><?= e($a['ip'] ?? '') ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>
<?php require __DIR__ . '/partials/footer.php';
