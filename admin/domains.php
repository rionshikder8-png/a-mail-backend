<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/mail/domain-authentication.php';
admin_guard('domains.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_only();
    $act = (string)($_POST['action'] ?? '');
    if ($act === 'check') {
        $d = q_one('SELECT * FROM domains WHERE id = ?', [(int)($_POST['id'] ?? 0)]);
        if ($d) {
            $sel = $d['dkim_selector'] ?: (string)env('DKIM_SELECTOR', 'amail');
            $r = domain_dns_check($d['name'], $sel);
            q('UPDATE domains SET mx_ok = ?, spf_ok = ?, dkim_ok = ?, dmarc_ok = ?, checked_at = UTC_TIMESTAMP(), dkim_selector = ? WHERE id = ?',
                [$r['mx'] ? 1 : 0, $r['spf'] ? 1 : 0, $r['dkim'] ? 1 : 0, $r['dmarc'] ? 1 : 0, $sel, $d['id']]);
            admin_log('domain.check', 'domain', (int)$d['id']);
            flash('ok', 'DNS checked just now. Results are what the resolver returned.');
        }
    } elseif ($act === 'add' && admin_can('settings.manage')) {
        $name = strtolower(trim((string)($_POST['name'] ?? '')));
        if (preg_match('/^(?:[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $name)) {
            q('INSERT IGNORE INTO domains (name, status) VALUES (?, "pending")', [$name]);
            admin_log('domain.add', 'domain', null, ['name' => $name]);
            flash('ok', 'Domain added as pending. Accounts can only be created on the primary domain.');
        } else {
            flash('err', 'Enter a valid domain name.');
        }
    }
    redirect('/admin/domains.php');
}

$domains = q_all('SELECT d.*, (SELECT COUNT(*) FROM users u WHERE u.domain_id = d.id AND u.status <> "deleted") AS accounts FROM domains d ORDER BY is_primary DESC, name');
$mark = static fn(int $ok): string => $ok ? badge('pass', 'ok') : badge('not verified', 'warn');
$sel = (string)env('DKIM_SELECTOR', 'amail');
$title = 'Domains';
require __DIR__ . '/partials/header.php';
?>
<div class="alert note">DNS status comes from live lookups when you press “Check DNS”. Nothing is assumed to be configured.</div>
<?php foreach ($domains as $d): ?>
<div class="card" style="margin-bottom:16px"><div class="hd"><h2><?= e($d['name']) ?> <?= $d['is_primary'] ? badge('primary', 'info') : '' ?></h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="check"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><button class="btn sm primary" type="submit">Check DNS</button></form></div>
<div class="bd grid cols-2"><dl class="kv"><dt>Status</dt><dd><?= status_badge($d['status']) ?></dd><dt>Accounts</dt><dd><?= (int)$d['accounts'] ?></dd><dt>Last checked</dt><dd><?= e($d['checked_at'] ? dt($d['checked_at']) : 'never') ?></dd></dl>
<dl class="kv"><dt>MX</dt><dd><?= $mark((int)$d['mx_ok']) ?></dd><dt>SPF</dt><dd><?= $mark((int)$d['spf_ok']) ?></dd><dt>DKIM</dt><dd><?= $mark((int)$d['dkim_ok']) ?></dd><dt>DMARC</dt><dd><?= $mark((int)$d['dmarc_ok']) ?></dd></dl></div></div>
<?php endforeach; ?>
<div class="card"><div class="hd"><h2>Records to publish for <?= e(config('domain')) ?></h2></div><div class="bd">
<table><thead><tr><th>Type</th><th>Host</th><th>Value</th></tr></thead><tbody>
<tr><td>MX</td><td class="mono">@</td><td class="mono">10 mail.<?= e(config('domain')) ?>.</td></tr>
<tr><td>TXT (SPF)</td><td class="mono">@</td><td class="mono">v=spf1 mx ip4:<em>YOUR_SERVER_IP</em> -all</td></tr>
<tr><td>TXT (DKIM)</td><td class="mono"><?= e($sel) ?>._domainkey</td><td class="mono">v=DKIM1; k=rsa; p=<em>PUBLIC_KEY</em></td></tr>
<tr><td>TXT (DMARC)</td><td class="mono">_dmarc</td><td class="mono">v=DMARC1; p=quarantine; rua=mailto:dmarc@<?= e(config('domain')) ?></td></tr>
<tr><td>A</td><td class="mono">mail</td><td class="mono"><em>YOUR_SERVER_IP</em></td></tr>
</tbody></table><p class="muted" style="margin-top:10px">Also set reverse DNS (PTR) for your server IP to <span class="mono">mail.<?= e(config('domain')) ?></span>. Generate the DKIM key pair with the command in the README.</p></div></div>
<?php if (admin_can('settings.manage')): ?>
<div class="card" style="margin-top:16px"><div class="hd"><h2>Add domain</h2></div><div class="bd"><form method="post" class="toolbar"><?= csrf_field() ?><input type="hidden" name="action" value="add"><input type="text" name="name" placeholder="example.com" required aria-label="Domain"><button class="btn" type="submit">Add</button></form></div></div>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php';
