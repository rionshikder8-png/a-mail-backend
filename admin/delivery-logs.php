<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/config/mail_config.php';
admin_guard('logs.view');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_only();
    admin_require('spam.manage');
    $qid = (int)($_POST['queue_id'] ?? 0);
    if (($_POST['action'] ?? '') === 'retry' && $qid) {
        q('UPDATE mail_queue SET status = "pending", attempts = 0, next_attempt_at = UTC_TIMESTAMP(), last_error = NULL WHERE id = ? AND status IN ("failed","deferred")', [$qid]);
        admin_log('queue.retry', 'queue', $qid);
        flash('ok', 'Queued for another delivery attempt.');
    }
    redirect('/admin/delivery-logs.php');
}

$per = 40;
$page = page_num();
$status = (string)($_GET['status'] ?? '');
$where = '1=1';
$args = [];
if (in_array($status, ['delivered', 'deferred', 'bounced', 'failed'], true)) {
    $where = 'status = ?';
    $args[] = $status;
}
$total = (int)q_val("SELECT COUNT(*) FROM delivery_logs WHERE $where", $args);
$rows = q_all("SELECT * FROM delivery_logs WHERE $where ORDER BY id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $args);
$qs = q_one('SELECT SUM(status="pending") p, SUM(status="deferred") d, SUM(status="failed") f, SUM(status="sent") s FROM mail_queue');
$stuck = q_all('SELECT id, rcpt_address, status, attempts, last_error, next_attempt_at FROM mail_queue WHERE status IN ("failed","deferred") ORDER BY id DESC LIMIT 15');

$title = 'Delivery logs';
require __DIR__ . '/partials/header.php';
?>
<?php if (!smtp_configured()): ?><div class="alert err"><b>No outbound relay is configured.</b> Messages to addresses outside <?= e(config('domain')) ?> are queued but cannot be delivered until SMTP_HOST is set in the server environment.</div><?php endif; ?>
<div class="grid cols-4">
  <div class="card stat"><div class="k">Waiting</div><div class="v"><?= (int)$qs['p'] ?></div></div>
  <div class="card stat"><div class="k">Deferred (retrying)</div><div class="v"><?= (int)$qs['d'] ?></div></div>
  <div class="card stat"><div class="k">Failed</div><div class="v"><?= (int)$qs['f'] ?></div></div>
  <div class="card stat"><div class="k">Delivered</div><div class="v"><?= (int)$qs['s'] ?></div></div>
</div>
<?php if ($stuck): ?>
<div class="card" style="margin-top:16px"><div class="hd"><h2>Needs attention</h2></div><div class="tablewrap"><table><thead><tr><th>Recipient</th><th>Status</th><th>Attempts</th><th>Last error</th><th></th></tr></thead><tbody>
<?php foreach ($stuck as $s): ?><tr><td><?= e($s['rcpt_address']) ?></td><td><?= status_badge($s['status']) ?></td><td><?= (int)$s['attempts'] ?></td><td class="muted"><?= e(mb_substr((string)$s['last_error'], 0, 120)) ?></td>
<td><?php if (admin_can('spam.manage')): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="retry"><input type="hidden" name="queue_id" value="<?= (int)$s['id'] ?>"><button class="btn sm" type="submit">Retry</button></form><?php endif; ?></td></tr>
<?php endforeach; ?></tbody></table></div></div>
<?php endif; ?>
<div class="toolbar" style="margin-top:16px"><form method="get"><select name="status" aria-label="Status"><option value="">All results</option><?php foreach (['delivered', 'deferred', 'bounced', 'failed'] as $s): ?><option value="<?= $s ?>"<?= $status === $s ? ' selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select><button class="btn" type="submit">Filter</button></form></div>
<div class="card">
<?php if (!$rows): ?><div class="empty">No delivery attempts recorded yet.</div><?php else: ?>
<div class="tablewrap"><table><thead><tr><th>Time</th><th>Recipient</th><th>Result</th><th>SMTP</th><th>Response</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td class="muted" style="white-space:nowrap"><?= e(dt($r['created_at'])) ?></td><td><?= e($r['rcpt_address']) ?></td><td><?= status_badge($r['status']) ?></td><td class="mono"><?= e($r['smtp_code'] ?? '') ?></td><td class="muted"><?= e(mb_substr((string)$r['response'], 0, 140)) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?= pager($total, $per, $page) ?><?php endif; ?></div>
<?php require __DIR__ . '/partials/footer.php';
