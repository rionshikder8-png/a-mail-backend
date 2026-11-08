<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/services/health_service.php';
admin_guard('system.view');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'smtptest') {
        require_once dirname(__DIR__) . '/mail/selftest.php';
        $t = smtp_selftest();
        admin_log('system.smtp_test', null, null, ['ok' => $t['ok'], 'step' => $t['step']]);
        flash($t['ok'] ? 'ok' : 'err', ($t['ok'] ? 'Mail sending OK: ' : 'Mail sending problem (' . $t['step'] . '): ') . $t['detail']);
    }
    redirect('/admin/system-health.php');
}
$jobs = [
    'process-outgoing' => ['Sends queued outgoing mail', 5],
    'scheduled-emails' => ['Sends scheduled mail', 5],
    'process-bounces' => ['Handles bounced mail', 30],
    'system-health' => ['Health snapshot', 30],
    'cleanup-tokens' => ['Expired token cleanup', 120],
    'cleanup-trash' => ['Trash cleanup', 1560],
    'storage-check' => ['Storage quota check', 1560],
];

$h = system_health();
$fails = count(array_filter($h['checks'], static fn($c) => $c['status'] === 'fail'));
$warns = count(array_filter($h['checks'], static fn($c) => $c['status'] === 'warn'));
$lastCron = [
    'Scheduled mail / snooze' => q_val('SELECT MAX(updated_at) FROM emails WHERE sent_state IN ("sent","queued")'),
    'Last delivery attempt' => q_val('SELECT MAX(created_at) FROM delivery_logs'),
    'Last inbound message' => q_val('SELECT MAX(created_at) FROM mail_logs WHERE direction = "inbound"'),
];
$title = 'System health';
require __DIR__ . '/partials/header.php';
?>
<div class="alert <?= $fails ? 'err' : ($warns ? 'note' : 'ok') ?>"><?= $fails ? "<b>$fails check(s) failing.</b> " : '' ?><?= $warns ? "$warns item(s) need configuration. " : '' ?><?= !$fails && !$warns ? 'All checks passed. ' : '' ?>Measured live at <?= e(gmdate('H:i:s')) ?> UTC.</div>
<div class="card"><div class="tablewrap"><table><thead><tr><th>Check</th><th>Status</th><th>Detail</th></tr></thead><tbody>
<?php foreach ($h['checks'] as $c): ?><tr><td><?= e($c['name']) ?></td><td><?= status_badge($c['status']) ?></td><td class="muted"><?= e($c['detail']) ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<div class="card" style="margin-top:16px"><div class="hd"><h2>Scheduled jobs (cron)</h2></div><div class="tablewrap"><table><thead><tr><th>Job</th><th>Purpose</th><th>Last ran</th><th>Status</th></tr></thead><tbody>
<?php foreach ($jobs as $job => [$what, $maxMin]):
    $last = setting('cron_last_' . $job, '');
    $age = $last !== '' ? (time() - strtotime($last . ' UTC')) / 60 : null;
    $st = $last === '' ? 'fail' : ($age > $maxMin ? 'warn' : 'ok'); ?>
<tr><td><code><?= e($job) ?></code></td><td class="muted"><?= e($what) ?></td><td><?= $last === '' ? 'never' : e(ago($last)) ?></td><td><?= status_badge($st) ?></td></tr>
<?php endforeach; ?>
</tbody></table></div><div class="bd"><p class="muted">“Never” means the cron job is not scheduled in cPanel yet (README step 7). Mail will not leave the queue until <code>process-outgoing</code> runs.</p></div></div>
<div class="card" style="margin-top:16px"><div class="hd"><h2>Mail sending test</h2></div><div class="bd">
<p class="muted">Connects to your SMTP server and logs in. Sends no message.</p>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="smtptest"><button class="btn primary" type="submit">Test SMTP login</button></form></div></div>
<div class="card" style="margin-top:16px"><div class="hd"><h2>Recent mail activity</h2></div><div class="bd"><dl class="kv">
<?php foreach ($lastCron as $k => $v): ?><dt><?= e($k) ?></dt><dd><?= $v ? e(ago((string)$v)) : 'never' ?></dd><?php endforeach; ?></dl>
<p class="muted">Cron jobs must be scheduled on the server (see README). If “Last delivery attempt” never updates while mail is queued, the outgoing job is not running.</p></div></div>
<?php require __DIR__ . '/partials/footer.php';
