<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
admin_guard('logs.view');

$per = 50;
$page = page_num();
$dir = (string)($_GET['direction'] ?? '');
$res = trim((string)($_GET['result'] ?? ''));
$search = trim((string)($_GET['q'] ?? ''));
$where = ['1=1'];
$args = [];
if (in_array($dir, ['inbound', 'outbound', 'internal'], true)) {
    $where[] = 'direction = ?';
    $args[] = $dir;
}
if ($res !== '') {
    $where[] = 'result = ?';
    $args[] = mb_substr($res, 0, 40);
}
if ($search !== '') {
    $like = '%' . addcslashes($search, '%_\\') . '%';
    $where[] = '(from_address LIKE ? OR to_address LIKE ? OR message_id LIKE ?)';
    array_push($args, $like, $like, $like);
}
$w = implode(' AND ', $where);
$total = (int)q_val("SELECT COUNT(*) FROM mail_logs WHERE $w", $args);
// Metadata only. Subjects are never stored in logs; only their length.
$rows = q_all("SELECT * FROM mail_logs WHERE $w ORDER BY id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $args);

$title = 'Mail logs';
require __DIR__ . '/partials/header.php';
?>
<div class="alert note">Logs record routing metadata only (addresses, size, result). Message subjects and contents are never stored here.</div>
<div class="toolbar"><form method="get">
  <input type="search" name="q" value="<?= e($search) ?>" placeholder="Address or message ID" aria-label="Search logs">
  <select name="direction" aria-label="Direction"><option value="">All directions</option><?php foreach (['inbound', 'outbound', 'internal'] as $d): ?><option value="<?= $d ?>"<?= $dir === $d ? ' selected' : '' ?>><?= ucfirst($d) ?></option><?php endforeach; ?></select>
  <input type="search" name="result" value="<?= e($res) ?>" placeholder="Result (e.g. delivered)" aria-label="Result">
  <button class="btn" type="submit">Filter</button></form></div>
<div class="card">
<?php if (!$rows): ?><div class="empty">No mail has been logged yet.</div><?php else: ?>
<div class="tablewrap"><table><thead><tr><th>Time</th><th>Direction</th><th>From</th><th>To</th><th>Size</th><th>Result</th><th>Detail</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?>
  <tr><td class="muted" style="white-space:nowrap"><?= e(dt($r['created_at'])) ?></td><td><?= badge($r['direction'], $r['direction'] === 'inbound' ? 'info' : '') ?></td>
  <td><?= e($r['from_address'] ?? '—') ?></td><td><?= e($r['to_address'] ?? '—') ?></td><td class="muted"><?= $r['size_bytes'] !== null ? e(format_bytes((int)$r['size_bytes'])) : '—' ?></td>
  <td><?= status_badge($r['result']) ?></td><td class="muted"><?= e($r['detail'] ?? '') ?></td></tr>
<?php endforeach; ?></tbody></table></div><?= pager($total, $per, $page) ?>
<?php endif; ?></div>
<?php require __DIR__ . '/partials/footer.php';
