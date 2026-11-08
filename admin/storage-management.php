<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/services/storage_service.php';
admin_guard('storage.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_only();
    if (($_POST['action'] ?? '') === 'recompute') {
        $n = 0;
        foreach (q_all('SELECT id FROM users WHERE status <> "deleted"') as $u) {
            storage_recompute((int)$u['id']);
            $n++;
        }
        admin_log('storage.recompute', null, null, ['users' => $n]);
        flash('ok', "Usage recalculated for $n accounts.");
    } elseif (($_POST['action'] ?? '') === 'purge_orphans') {
        require_once dirname(__DIR__) . '/services/sync_service.php';
        $n = sync_clean_orphan_uploads();
        admin_log('storage.purge_orphans', null, null, ['removed' => $n]);
        flash('ok', "Removed $n unattached uploads and temporary files.");
    }
    redirect('/admin/storage-management.php');
}

$t = q_one('SELECT COALESCE(SUM(mail_bytes),0) m, COALESCE(SUM(attachments_bytes),0) a, COALESCE(SUM(trash_bytes),0) t FROM storage_usage');
$allocated = (int)q_val('SELECT COALESCE(SUM(quota_bytes),0) FROM storage_quotas');
$disk = @disk_free_space(storage_path());
$diskTotal = @disk_total_space(storage_path());
$top = q_all('SELECT u.id, u.username, su.mail_bytes + su.attachments_bytes + su.trash_bytes AS used, sq.quota_bytes AS quota
              FROM storage_usage su JOIN users u ON u.id = su.user_id LEFT JOIN storage_quotas sq ON sq.user_id = u.id
              WHERE u.status <> "deleted" ORDER BY used DESC LIMIT 15');
$orphans = q_one('SELECT COUNT(*) n, COALESCE(SUM(size_bytes),0) b FROM attachments WHERE email_id IS NULL');
$title = 'Storage';
require __DIR__ . '/partials/header.php';
?>
<div class="grid cols-4">
  <div class="card stat"><div class="k">Mail</div><div class="v"><?= e(format_bytes((int)$t['m'])) ?></div></div>
  <div class="card stat"><div class="k">Attachments</div><div class="v"><?= e(format_bytes((int)$t['a'])) ?></div></div>
  <div class="card stat"><div class="k">In trash</div><div class="v"><?= e(format_bytes((int)$t['t'])) ?></div></div>
  <div class="card stat"><div class="k">Quota allocated</div><div class="v"><?= e(format_bytes($allocated)) ?></div><div class="s">Across all accounts</div></div>
</div>
<?php if ($disk !== false && $diskTotal): $dp = (int)round((1 - $disk / $diskTotal) * 100); ?>
<div class="card" style="margin-top:16px"><div class="hd"><h2>Server disk</h2></div><div class="bd"><div class="meter <?= $dp > 90 ? 'danger' : ($dp > 75 ? 'warn' : '') ?>"><i style="width:<?= $dp ?>%"></i></div><p class="muted" style="margin:8px 0 0"><?= e(format_bytes((int)$disk)) ?> free of <?= e(format_bytes((int)$diskTotal)) ?> (<?= $dp ?>% used)</p></div></div>
<?php endif; ?>
<div class="card" style="margin-top:16px"><div class="hd"><h2>Largest mailboxes</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="recompute"><button class="btn sm" type="submit">Recalculate usage</button></form></div>
<?php if (!$top): ?><div class="empty">No storage data yet.</div><?php else: ?><div class="tablewrap"><table><thead><tr><th>Account</th><th>Used</th><th>Quota</th><th style="width:30%">Usage</th></tr></thead><tbody>
<?php foreach ($top as $r): $pct = $r['quota'] > 0 ? min(100, (int)round($r['used'] / $r['quota'] * 100)) : 0; ?>
<tr><td><a href="/admin/user-details.php?id=<?= (int)$r['id'] ?>"><?= e($r['username']) ?>@<?= e(config('domain')) ?></a></td><td><?= e(format_bytes((int)$r['used'])) ?></td><td><?= e(format_bytes((int)$r['quota'])) ?></td>
<td><div class="meter <?= $pct > 90 ? 'danger' : ($pct > 75 ? 'warn' : '') ?>"><i style="width:<?= $pct ?>%"></i></div></td></tr>
<?php endforeach; ?></tbody></table></div><?php endif; ?></div>
<div class="card" style="margin-top:16px"><div class="hd"><h2>Cleanup</h2></div><div class="bd">
  <p class="muted" style="margin-top:0"><?= (int)$orphans['n'] ?> uploads (<?= e(format_bytes((int)$orphans['b'])) ?>) are not attached to any message. Uploads older than 24 hours are removed automatically by the nightly job.</p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="purge_orphans"><button class="btn" type="submit" data-confirm="Remove uploads older than 24 hours that are not attached to any message?">Clean up now</button></form></div></div>
<?php require __DIR__ . '/partials/footer.php';
