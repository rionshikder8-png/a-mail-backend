<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
$admin = admin_guard('dashboard.view');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_only();
    $act = (string)($_POST['action'] ?? '');
    if ($act === 'name') {
        $n = trim((string)($_POST['name'] ?? ''));
        if ($n !== '' && mb_strlen($n) <= 100) {
            q('UPDATE admins SET name = ? WHERE id = ?', [$n, $admin['id']]);
            admin_log('profile.name');
            flash('ok', 'Name updated.');
        }
    } elseif ($act === 'password') {
        $row = q_one('SELECT password_hash FROM admins WHERE id = ?', [$admin['id']]);
        $new = (string)($_POST['new_password'] ?? '');
        if (!password_verify((string)($_POST['current_password'] ?? ''), $row['password_hash'])) {
            flash('err', 'Your current password is incorrect.');
        } elseif (strlen($new) < 12) {
            flash('err', 'Use at least 12 characters for an administrator password.');
        } elseif ($new !== (string)($_POST['confirm_password'] ?? '')) {
            flash('err', 'The new passwords do not match.');
        } else {
            q('UPDATE admins SET password_hash = ? WHERE id = ?', [password_hash_secure($new), $admin['id']]);
            session_regenerate_id(true);
            admin_log('profile.password');
            flash('ok', 'Password changed.');
        }
    }
    redirect('/admin/profile.php');
}
$mine = q_all('SELECT action, target_type, target_id, ip, created_at FROM admin_activity_logs WHERE admin_id = ? ORDER BY id DESC LIMIT 20', [$admin['id']]);
$full = q_one('SELECT last_login_at, created_at FROM admins WHERE id = ?', [$admin['id']]);
$title = 'My profile';
require __DIR__ . '/partials/header.php';
?>
<div class="grid cols-2">
  <div class="card"><div class="hd"><h2>Profile</h2></div><div class="bd">
    <dl class="kv"><dt>Email</dt><dd><?= e($admin['email']) ?></dd><dt>Role</dt><dd><?= e($admin['role']) ?></dd><dt>Last sign-in</dt><dd><?= e(dt($full['last_login_at'])) ?></dd></dl><hr style="border:0;border-top:1px solid var(--outline);margin:16px 0">
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="name"><div class="form-row"><label for="name">Display name</label><input id="name" type="text" name="name" value="<?= e($admin['name']) ?>" maxlength="100" required></div><button class="btn" type="submit">Save</button></form></div></div>
  <div class="card"><div class="hd"><h2>Change password</h2></div><form method="post" class="bd"><?= csrf_field() ?><input type="hidden" name="action" value="password">
    <div class="form-row"><label for="cp">Current password</label><input id="cp" type="password" name="current_password" required autocomplete="current-password"></div>
    <div class="form-row"><label for="np">New password (12+ characters)</label><input id="np" type="password" name="new_password" minlength="12" required autocomplete="new-password"></div>
    <div class="form-row"><label for="cf">Confirm new password</label><input id="cf" type="password" name="confirm_password" minlength="12" required autocomplete="new-password"></div>
    <button class="btn primary" type="submit">Change password</button></form></div>
</div>
<div class="card" style="margin-top:16px"><div class="hd"><h2>My recent activity</h2></div>
<?php if (!$mine): ?><div class="empty">No activity yet.</div><?php else: ?><div class="tablewrap"><table><tbody><?php foreach ($mine as $m): ?><tr><td><?= e($m['action']) ?></td><td class="muted"><?= $m['target_type'] ? e($m['target_type']) . ' #' . (int)$m['target_id'] : '' ?></td><td class="mono"><?= e($m['ip'] ?? '') ?></td><td class="muted"><?= e(ago($m['created_at'])) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>
<?php require __DIR__ . '/partials/footer.php';
