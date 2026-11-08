<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
admin_guard('settings.manage');

const EDITABLE = [
    'registration_open' => ['bool', 'Allow new registrations'],
    'maintenance_mode' => ['bool', 'Maintenance mode (API returns 503, sign-in still allowed)'],
    'require_verification' => ['bool', 'Require verified recovery email before sending mail'],
    'login_alerts' => ['bool', 'Notify users of sign-ins from a new device'],
    'trash_retention_days' => ['int', 'Days to keep Trash before permanent deletion'],
    'spam_retention_days' => ['int', 'Days to keep Spam before permanent deletion'],
    'max_recipients' => ['int', 'Maximum recipients per message'],
    'daily_send_limit' => ['int', 'Maximum recipients per user per day'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_only();
    foreach (EDITABLE as $k => [$type]) {
        if ($type === 'bool') {
            set_setting($k, isset($_POST[$k]) ? '1' : '0');
        } else {
            set_setting($k, (string)max(1, min(100000, (int)($_POST[$k] ?? 1))));
        }
    }
    admin_log('settings.update');
    flash('ok', 'Settings saved.');
    redirect('/admin/settings.php');
}
$defaults = ['registration_open' => '1', 'maintenance_mode' => '0', 'require_verification' => '0', 'login_alerts' => '1', 'trash_retention_days' => '30', 'spam_retention_days' => '30', 'max_recipients' => '50', 'daily_send_limit' => '500'];
$title = 'Settings';
require __DIR__ . '/partials/header.php';
?>
<div class="alert note">Secrets (database, SMTP, signing keys) are never editable here. They live in the server's <span class="mono">.env</span> file.</div>
<div class="card"><form method="post" class="bd"><?= csrf_field() ?>
<?php foreach (EDITABLE as $k => [$type, $label]): $v = setting($k, $defaults[$k]); ?>
  <?php if ($type === 'bool'): ?>
  <div class="form-row"><label style="font-weight:500"><input type="checkbox" name="<?= e($k) ?>" <?= $v === '1' ? 'checked' : '' ?>> <?= e($label) ?></label></div>
  <?php else: ?>
  <div class="form-row" style="max-width:320px"><label for="<?= e($k) ?>"><?= e($label) ?></label><input id="<?= e($k) ?>" type="number" min="1" name="<?= e($k) ?>" value="<?= (int)$v ?>"></div>
  <?php endif; ?>
<?php endforeach; ?>
  <button class="btn primary" type="submit">Save settings</button></form></div>
<?php if (admin_can('admins.manage')): ?>
<?php
$admins = q_all('SELECT id, email, name, role, status, last_login_at FROM admins ORDER BY id');
?>
<div class="card" style="margin-top:16px"><div class="hd"><h2>Administrators</h2></div><div class="tablewrap"><table><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Last sign-in</th></tr></thead><tbody>
<?php foreach ($admins as $a): ?><tr><td><?= e($a['name']) ?></td><td><?= e($a['email']) ?></td><td><?= e($a['role']) ?></td><td><?= status_badge($a['status']) ?></td><td class="muted"><?= e(ago($a['last_login_at'])) ?></td></tr><?php endforeach; ?></tbody></table></div>
<div class="bd muted">Create administrators from the server with <span class="mono">php database/create-admin.php</span>. Accounts cannot be created from the web, which keeps privileged access off the internet.</div></div>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php';
