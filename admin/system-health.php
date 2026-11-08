<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/services/health_service.php';
admin_guard('system.view');

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
<div class="card" style="margin-top:16px"><div class="hd"><h2>Recent mail activity</h2></div><div class="bd"><dl class="kv">
<?php foreach ($lastCron as $k => $v): ?><dt><?= e($k) ?></dt><dd><?= $v ? e(ago((string)$v)) : 'never' ?></dd><?php endforeach; ?></dl>
<p class="muted">Cron jobs must be scheduled on the server (see README). If “Last delivery attempt” never updates while mail is queued, the outgoing job is not running.</p></div></div>
<?php require __DIR__ . '/partials/footer.php';
