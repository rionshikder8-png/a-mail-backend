<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
admin_guard('spam.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_only();
    if (($_POST['action'] ?? '') === 'block') {
        $addr = strtolower(trim((string)($_POST['address'] ?? '')));
        if (valid_email($addr)) {
            $list = array_filter(array_map('trim', explode("\n", setting('blocked_senders', ''))));
            $list[] = $addr;
            set_setting('blocked_senders', implode("\n", array_values(array_unique($list))));
            admin_log('spam.block_sender', 'sender', null, ['address' => $addr]);
            flash('ok', "$addr will be filed as spam from now on.");
        } else {
            flash('err', 'Enter a valid email address.');
        }
    } elseif (($_POST['action'] ?? '') === 'unblock') {
        $addr = strtolower(trim((string)($_POST['address'] ?? '')));
        $list = array_filter(array_map('trim', explode("\n", setting('blocked_senders', ''))), static fn($x) => $x !== $addr);
        set_setting('blocked_senders', implode("\n", $list));
        admin_log('spam.unblock_sender', 'sender', null, ['address' => $addr]);
        flash('ok', 'Sender unblocked.');
    }
    redirect('/admin/spam-management.php');
}

$blocked = array_values(array_filter(array_map('trim', explode("\n", setting('blocked_senders', '')))));
// Aggregates only: top sender domains filed as spam, never message content.
$stats = q_one('SELECT COUNT(*) total, SUM(created_at > (UTC_TIMESTAMP() - INTERVAL 1 DAY)) day FROM mail_logs WHERE result = "spam"');
$top = q_all('SELECT SUBSTRING_INDEX(from_address, "@", -1) AS dom, COUNT(*) n FROM mail_logs WHERE result = "spam" AND from_address IS NOT NULL AND created_at > (UTC_TIMESTAMP() - INTERVAL 30 DAY) GROUP BY dom ORDER BY n DESC LIMIT 10');
$recent = q_all('SELECT from_address, to_address, detail, created_at FROM mail_logs WHERE result = "spam" ORDER BY id DESC LIMIT 20');

$title = 'Spam management';
require __DIR__ . '/partials/header.php';
?>
<div class="grid cols-3">
  <div class="card stat"><div class="k">Filed as spam (24 h)</div><div class="v"><?= (int)($stats['day'] ?? 0) ?></div></div>
  <div class="card stat"><div class="k">Filed as spam (all time)</div><div class="v"><?= (int)($stats['total'] ?? 0) ?></div></div>
  <div class="card stat"><div class="k">Blocked senders</div><div class="v"><?= count($blocked) ?></div></div>
</div>
<div class="grid cols-2" style="margin-top:16px">
  <div class="card"><div class="hd"><h2>Blocked senders</h2></div><div class="bd">
    <form method="post" class="toolbar"><?= csrf_field() ?><input type="hidden" name="action" value="block"><input type="email" name="address" placeholder="sender@example.com" required aria-label="Sender address"><button class="btn primary" type="submit">Block</button></form>
    <?php if (!$blocked): ?><p class="muted">No senders are blocked.</p><?php else: ?><table><tbody><?php foreach ($blocked as $b): ?>
      <tr><td><?= e($b) ?></td><td style="text-align:right"><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="unblock"><input type="hidden" name="address" value="<?= e($b) ?>"><button class="btn sm" type="submit">Unblock</button></form></td></tr>
    <?php endforeach; ?></tbody></table><?php endif; ?></div></div>
  <div class="card"><div class="hd"><h2>Top spam sources (30 days)</h2></div>
    <?php if (!$top): ?><div class="empty">Nothing has been filed as spam.</div><?php else: ?><table><tbody><?php foreach ($top as $t): ?><tr><td><?= e($t['dom']) ?></td><td style="text-align:right"><?= (int)$t['n'] ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></div>
</div>
<div class="card" style="margin-top:16px"><div class="hd"><h2>Recent spam decisions</h2></div>
<?php if (!$recent): ?><div class="empty">No spam decisions recorded yet.</div><?php else: ?><div class="tablewrap"><table><thead><tr><th>Time</th><th>From</th><th>To</th><th>Reasons</th></tr></thead><tbody>
<?php foreach ($recent as $r): ?><tr><td class="muted"><?= e(ago($r['created_at'])) ?></td><td><?= e($r['from_address'] ?? '') ?></td><td><?= e($r['to_address'] ?? '') ?></td><td class="muted"><?= e($r['detail'] ?? '') ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>
<?php require __DIR__ . '/partials/footer.php';
