<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/services/user_service.php';
require_once dirname(__DIR__) . '/services/storage_service.php';
require_once dirname(__DIR__) . '/services/notification_service.php';
$admin = admin_guard('users.view');

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$u = q_one('SELECT u.*, p.display_name, p.recovery_email, p.recovery_phone, COALESCE(sp.name,"Free") AS plan_name
            FROM users u JOIN user_profiles p ON p.user_id = u.id LEFT JOIN subscription_plans sp ON sp.id = u.plan_id WHERE u.id = ? AND u.status <> "deleted"', [$id]);
if (!$u) {
    http_response_code(404);
    $title = 'User not found';
    require __DIR__ . '/partials/header.php';
    echo '<div class="card"><div class="empty">That account does not exist.</div></div>';
    require __DIR__ . '/partials/footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_only();
    $act = (string)($_POST['action'] ?? '');
    switch ($act) {
        case 'suspend':
            admin_require('users.suspend');
            if ((string)($_POST['reason'] ?? '') === '') {
                flash('err', 'Enter a reason for the suspension.');
                break;
            }
            q('UPDATE users SET status = "suspended", suspended_reason = ? WHERE id = ?', [mb_substr((string)$_POST['reason'], 0, 250), $id]);
            sessions_revoke_all($id);
            admin_log('user.suspend', 'user', $id, ['reason' => mb_substr((string)$_POST['reason'], 0, 250)]);
            flash('ok', 'Account suspended and all devices signed out.');
            break;
        case 'activate':
            admin_require('users.suspend');
            q('UPDATE users SET status = "active", suspended_reason = NULL, failed_logins = 0, locked_until = NULL WHERE id = ?', [$id]);
            admin_log('user.activate', 'user', $id);
            flash('ok', 'Account reactivated.');
            break;
        case 'reset_2fa':
            admin_require('users.reset_2fa');
            q('UPDATE users SET two_factor_enabled = 0 WHERE id = ?', [$id]);
            admin_log('user.reset_2fa', 'user', $id);
            security_log($id, 'two_factor_reset_by_admin', 'warning');
            notify_user($id, 'security', 'Two-factor sign-in turned off', 'An administrator turned off two-factor sign-in on your account.');
            flash('ok', 'Two-factor sign-in turned off for this account.');
            break;
        case 'signout_all':
            admin_require('users.manage');
            sessions_revoke_all($id);
            admin_log('user.signout_all', 'user', $id);
            flash('ok', 'All devices signed out.');
            break;
        case 'plan':
            admin_require('users.manage');
            $pid = (int)($_POST['plan_id'] ?? 0);
            $plan = q_one('SELECT * FROM subscription_plans WHERE id = ? AND is_active = 1', [$pid]);
            if ($plan) {
                q('UPDATE users SET plan_id = ? WHERE id = ?', [$pid, $id]);
                q('INSERT INTO storage_quotas (user_id, quota_bytes) VALUES (?,?) ON DUPLICATE KEY UPDATE quota_bytes = VALUES(quota_bytes)', [$id, $plan['quota_bytes']]);
                q('UPDATE subscriptions SET status = "cancelled", ends_at = UTC_TIMESTAMP() WHERE user_id = ? AND status = "active"', [$id]);
                q('INSERT INTO subscriptions (user_id, plan_id, status, note) VALUES (?,?, "active", ?)', [$id, $pid, 'Set by admin ' . $admin['email']]);
                admin_log('user.plan', 'user', $id, ['plan' => $plan['code']]);
                flash('ok', 'Plan changed to ' . $plan['name'] . '.');
            }
            break;
        case 'quota':
            admin_require('storage.manage');
            $mb = max(1, (int)($_POST['quota_mb'] ?? 0));
            q('INSERT INTO storage_quotas (user_id, quota_bytes) VALUES (?,?) ON DUPLICATE KEY UPDATE quota_bytes = VALUES(quota_bytes)', [$id, $mb * 1048576]);
            admin_log('user.quota', 'user', $id, ['mb' => $mb]);
            flash('ok', 'Storage quota updated.');
            break;
        case 'delete':
            admin_require('users.delete');
            if (strtolower(trim((string)($_POST['confirm'] ?? ''))) !== strtolower($u['username'])) {
                flash('err', 'Type the username exactly to confirm deletion.');
                break;
            }
            admin_log('user.delete', 'user', $id, ['username' => $u['username']]);
            user_purge($id);
            flash('ok', 'Account and all of its data were permanently deleted.');
            redirect('/admin/users.php');
    }
    redirect('/admin/user-details.php?id=' . $id);
}

$storage = storage_dto($id);
$devices = q_all('SELECT device_name, platform, last_active, last_ip FROM devices WHERE user_id = ? AND revoked_at IS NULL ORDER BY last_active DESC LIMIT 10', [$id]);
$logins = q_all('SELECT success, ip, device_name, reason, created_at FROM login_history WHERE user_id = ? ORDER BY id DESC LIMIT 10', [$id]);
$counts = q_one('SELECT COUNT(*) total, SUM(folder="inbox") inbox, SUM(folder="sent") sent FROM emails WHERE user_id = ?', [$id]);
$plans = q_all('SELECT id, name FROM subscription_plans WHERE is_active = 1 ORDER BY sort_order');
$pct = $storage['quota_bytes'] > 0 ? min(100, (int)round($storage['used_bytes'] / $storage['quota_bytes'] * 100)) : 0;

$title = $u['username'] . '@' . config('domain');
require __DIR__ . '/partials/header.php';
?>
<div class="alert note">For privacy, administrators can see account details and counts only. Message subjects and contents are never shown here.</div>
<div class="grid cols-2">
  <div class="card"><div class="hd"><h2>Account</h2><?= status_badge($u['status']) ?></div><div class="bd"><dl class="kv">
    <dt>Address</dt><dd><?= e($u['username']) ?>@<?= e(config('domain')) ?></dd>
    <dt>Name</dt><dd><?= e($u['display_name']) ?></dd>
    <dt>Recovery email</dt><dd><?= e($u['recovery_email'] ?: '—') ?> <?= $u['email_verified'] ? badge('verified', 'ok') : badge('unverified', 'warn') ?></dd>
    <dt>Recovery phone</dt><dd><?= e($u['recovery_phone'] ?: '—') ?></dd>
    <dt>Two-factor</dt><dd><?= $u['two_factor_enabled'] ? badge('on', 'ok') : badge('off') ?></dd>
    <dt>Plan</dt><dd><?= e($u['plan_name']) ?></dd>
    <dt>Joined</dt><dd><?= e(dt($u['created_at'])) ?></dd>
    <dt>Last sign-in</dt><dd><?= e(dt($u['last_login_at'])) ?></dd>
    <?php if ($u['status'] === 'suspended'): ?><dt>Suspended for</dt><dd><?= e($u['suspended_reason'] ?: '—') ?></dd><?php endif; ?>
  </dl></div></div>
  <div class="card"><div class="hd"><h2>Storage and mail</h2></div><div class="bd">
    <div class="meter <?= $pct > 90 ? 'danger' : ($pct > 75 ? 'warn' : '') ?>"><i style="width:<?= $pct ?>%"></i></div>
    <p class="muted" style="margin:8px 0 14px"><?= e(format_bytes($storage['used_bytes'])) ?> of <?= e(format_bytes($storage['quota_bytes'])) ?> (<?= $pct ?>%)</p>
    <dl class="kv"><dt>Messages</dt><dd><?= number_format((int)$counts['total']) ?> (<?= (int)$counts['inbox'] ?> inbox, <?= (int)$counts['sent'] ?> sent)</dd>
    <dt>Attachments</dt><dd><?= e(format_bytes($storage['attachments_bytes'])) ?></dd></dl>
  </div></div>
</div>

<div class="grid cols-2" style="margin-top:16px">
  <div class="card"><div class="hd"><h2>Devices</h2></div>
  <?php if (!$devices): ?><div class="empty">No signed-in devices.</div><?php else: ?><div class="tablewrap"><table><tbody>
    <?php foreach ($devices as $d): ?><tr><td><?= e($d['device_name']) ?><div class="muted"><?= e($d['platform']) ?> · <?= e($d['last_ip'] ?: '—') ?></div></td><td class="muted"><?= e(ago($d['last_active'])) ?></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?></div>
  <div class="card"><div class="hd"><h2>Recent sign-ins</h2></div>
  <?php if (!$logins): ?><div class="empty">No sign-in history.</div><?php else: ?><div class="tablewrap"><table><tbody>
    <?php foreach ($logins as $l): ?><tr><td><?= $l['success'] ? badge('success', 'ok') : badge('failed', 'fail') ?> <span class="muted"><?= e($l['reason'] ?: '') ?></span><div class="muted"><?= e($l['ip'] ?: '—') ?> · <?= e($l['device_name'] ?: 'unknown device') ?></div></td><td class="muted"><?= e(ago($l['created_at'])) ?></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?></div>
</div>

<div class="card" style="margin-top:16px"><div class="hd"><h2>Actions</h2></div><div class="bd grid cols-2">
<?php if (admin_can('users.suspend')): ?>
  <?php if ($u['status'] === 'active'): ?>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="suspend">
    <div class="form-row"><label for="reason">Suspend account</label><input id="reason" name="reason" type="text" maxlength="250" placeholder="Reason (shown in the audit log)" required></div>
    <button class="btn danger" type="submit" data-confirm="Suspend this account and sign out every device?">Suspend</button></form>
  <?php else: ?>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="activate"><div class="form-row"><label>Account is suspended</label></div><button class="btn primary" type="submit">Reactivate</button></form>
  <?php endif; ?>
<?php endif; ?>
<?php if (admin_can('users.manage')): ?>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="plan">
    <div class="form-row"><label for="plan_id">Plan</label><select id="plan_id" name="plan_id"><?php foreach ($plans as $p): ?><option value="<?= (int)$p['id'] ?>"<?= $p['name'] === $u['plan_name'] ? ' selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
    <button class="btn" type="submit">Change plan</button></form>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="signout_all"><div class="form-row"><label>Sessions</label></div><button class="btn" type="submit" data-confirm="Sign this user out of every device?">Sign out all devices</button></form>
<?php endif; ?>
<?php if (admin_can('storage.manage')): ?>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="quota">
    <div class="form-row"><label for="quota_mb">Storage quota (MB)</label><input id="quota_mb" name="quota_mb" type="number" min="1" value="<?= (int)round($storage['quota_bytes'] / 1048576) ?>"></div>
    <button class="btn" type="submit">Set quota</button></form>
<?php endif; ?>
<?php if (admin_can('users.reset_2fa') && $u['two_factor_enabled']): ?>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="reset_2fa"><div class="form-row"><label>Locked out of two-factor?</label></div><button class="btn" type="submit" data-confirm="Turn off two-factor sign-in for this user?">Turn off two-factor</button></form>
<?php endif; ?>
<?php if (admin_can('users.delete')): ?>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="delete">
    <div class="form-row"><label for="confirm">Delete permanently</label><input id="confirm" name="confirm" type="text" placeholder="Type “<?= e($u['username']) ?>” to confirm" autocomplete="off"></div>
    <button class="btn danger" type="submit" data-confirm="This permanently deletes the account, all mail and files. This cannot be undone.">Delete account</button></form>
<?php endif; ?>
</div></div>
<?php require __DIR__ . '/partials/footer.php';
