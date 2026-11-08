<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
admin_guard('reports.view');

$range = (int)($_GET['days'] ?? 30);
$range = in_array($range, [7, 30, 90], true) ? $range : 30;

if (($_GET['export'] ?? '') === 'csv') {
    $rows = q_all('SELECT DATE(created_at) AS day, SUM(direction="inbound") AS inbound, SUM(direction="outbound") AS outbound, SUM(direction="internal") AS internal_mail,
                   SUM(result IN ("failed","bounce","rejected_quota","no_such_mailbox")) AS problems
                   FROM mail_logs WHERE created_at > (UTC_TIMESTAMP() - INTERVAL ' . $range . ' DAY) GROUP BY DATE(created_at) ORDER BY day');
    admin_log('report.export', 'report', null, ['days' => $range]);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="amail-mail-report-' . $range . 'd.csv"');
    $o = fopen('php://output', 'w');
    fputcsv($o, ['day', 'inbound', 'outbound', 'internal', 'problems']);
    foreach ($rows as $r) {
        fputcsv($o, [$r['day'], $r['inbound'], $r['outbound'], $r['internal_mail'], $r['problems']]);
    }
    exit;
}

$signups = q_all('SELECT DATE(created_at) d, COUNT(*) n FROM users WHERE created_at > (UTC_TIMESTAMP() - INTERVAL ' . $range . ' DAY) GROUP BY DATE(created_at) ORDER BY d');
$mail = q_all('SELECT direction, result, COUNT(*) n FROM mail_logs WHERE created_at > (UTC_TIMESTAMP() - INTERVAL ' . $range . ' DAY) GROUP BY direction, result ORDER BY n DESC');
$plans = q_all('SELECT COALESCE(sp.name,"Free") AS plan, COUNT(*) n FROM users u LEFT JOIN subscription_plans sp ON sp.id = u.plan_id WHERE u.status <> "deleted" GROUP BY plan ORDER BY n DESC');
$totals = q_one('SELECT COUNT(*) total, SUM(two_factor_enabled) tfa, SUM(email_verified) verified FROM users WHERE status <> "deleted"');
$retained = q_one('SELECT COUNT(*) n FROM users WHERE created_at < (UTC_TIMESTAMP() - INTERVAL 7 DAY) AND last_login_at > (UTC_TIMESTAMP() - INTERVAL 7 DAY) AND status = "active"');
$old = (int)q_val('SELECT COUNT(*) FROM users WHERE created_at < (UTC_TIMESTAMP() - INTERVAL 7 DAY) AND status <> "deleted"');
$total = max(1, (int)$totals['total']);
$title = 'Reports';
require __DIR__ . '/partials/header.php';
?>
<div class="toolbar"><form method="get"><select name="days" aria-label="Range"><?php foreach ([7, 30, 90] as $d): ?><option value="<?= $d ?>"<?= $range === $d ? ' selected' : '' ?>>Last <?= $d ?> days</option><?php endforeach; ?></select><button class="btn" type="submit">Apply</button></form>
<a class="btn" href="<?= e(qs(['export' => 'csv'])) ?>">Export mail volume (CSV)</a></div>
<div class="grid cols-4">
  <div class="card stat"><div class="k">Accounts</div><div class="v"><?= number_format((int)$totals['total']) ?></div></div>
  <div class="card stat"><div class="k">Two-factor enabled</div><div class="v"><?= round((int)$totals['tfa'] / $total * 100) ?>%</div></div>
  <div class="card stat"><div class="k">Recovery email verified</div><div class="v"><?= round((int)$totals['verified'] / $total * 100) ?>%</div></div>
  <div class="card stat"><div class="k">7-day return rate</div><div class="v"><?= $old ? round((int)$retained['n'] / $old * 100) . '%' : '—' ?></div><div class="s">Accounts older than 7 days that signed in this week</div></div>
</div>
<div class="grid cols-2" style="margin-top:16px">
  <div class="card"><div class="hd"><h2>Mail by outcome (<?= $range ?> days)</h2></div>
  <?php if (!$mail): ?><div class="empty">No mail activity in this period.</div><?php else: ?><table><thead><tr><th>Direction</th><th>Result</th><th style="text-align:right">Count</th></tr></thead><tbody><?php foreach ($mail as $m): ?><tr><td><?= e($m['direction']) ?></td><td><?= status_badge($m['result']) ?></td><td style="text-align:right"><?= number_format((int)$m['n']) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></div>
  <div class="card"><div class="hd"><h2>Accounts by plan</h2></div>
  <?php if (!$plans): ?><div class="empty">No accounts yet.</div><?php else: ?><table><tbody><?php foreach ($plans as $p): ?><tr><td><?= e($p['plan']) ?></td><td style="text-align:right"><?= number_format((int)$p['n']) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></div>
</div>
<div class="card" style="margin-top:16px"><div class="hd"><h2>New accounts per day</h2></div>
<?php if (!$signups): ?><div class="empty">No sign-ups in this period.</div><?php else: ?><div class="tablewrap"><table><thead><tr><th>Day</th><th style="text-align:right">New accounts</th></tr></thead><tbody><?php foreach ($signups as $s): ?><tr><td><?= e($s['d']) ?></td><td style="text-align:right"><?= (int)$s['n'] ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>
<?php require __DIR__ . '/partials/footer.php';
