<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
$admin = admin_guard('abuse.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_only();
    $id = (int)($_POST['id'] ?? 0);
    $to = (string)($_POST['status'] ?? '');
    if ($id && in_array($to, ['investigating', 'resolved', 'dismissed'], true)) {
        q('UPDATE abuse_reports SET status = ?, resolved_by = ?, resolved_at = IF(? IN ("resolved","dismissed"), UTC_TIMESTAMP(), NULL) WHERE id = ?', [$to, $admin['id'], $to, $id]);
        admin_log('abuse.' . $to, 'abuse_report', $id);
        flash('ok', 'Report updated.');
    }
    redirect('/admin/abuse-reports.php' . (isset($_GET['status']) ? '?status=' . urlencode((string)$_GET['status']) : ''));
}

$per = 25;
$page = page_num();
$status = (string)($_GET['status'] ?? 'open');
$where = in_array($status, ['open', 'investigating', 'resolved', 'dismissed'], true) ? 'r.status = ?' : '1=1';
$args = $where === '1=1' ? [] : [$status];
$total = (int)q_val("SELECT COUNT(*) FROM abuse_reports r WHERE $where", $args);
$rows = q_all("SELECT r.*, u.username AS reporter FROM abuse_reports r LEFT JOIN users u ON u.id = r.reporter_user_id WHERE $where ORDER BY r.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $args);

$title = 'Abuse reports';
require __DIR__ . '/partials/header.php';
?>
<div class="toolbar"><form method="get"><select name="status" aria-label="Status"><option value="all"<?= $status === 'all' ? ' selected' : '' ?>>All</option><?php foreach (['open', 'investigating', 'resolved', 'dismissed'] as $s): ?><option value="<?= $s ?>"<?= $status === $s ? ' selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select><button class="btn" type="submit">Filter</button></form></div>
<div class="card">
<?php if (!$rows): ?><div class="empty">No reports in this view.</div><?php else: ?>
<div class="tablewrap"><table><thead><tr><th>Reported address</th><th>Category</th><th>Reported by</th><th>Details</th><th>Status</th><th>Filed</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?>
  <tr><td><?= e($r['reported_address']) ?></td><td><?= badge($r['category']) ?></td><td><?= e($r['reporter'] ? $r['reporter'] . '@' . config('domain') : ($r['reporter_email'] ?: 'anonymous')) ?></td>
  <td class="muted" style="max-width:300px"><?= e(mb_substr((string)$r['description'], 0, 200)) ?></td><td><?= status_badge($r['status']) ?></td><td class="muted"><?= e(ago($r['created_at'])) ?></td>
  <td style="white-space:nowrap"><?php foreach (['investigating' => 'Investigate', 'resolved' => 'Resolve', 'dismissed' => 'Dismiss'] as $k => $label): if ($r['status'] === $k) continue; ?>
    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="status" value="<?= $k ?>"><button class="btn sm" type="submit"><?= $label ?></button></form>
  <?php endforeach; ?></td></tr>
<?php endforeach; ?></tbody></table></div><?= pager($total, $per, $page) ?>
<?php endif; ?></div>
<?php require __DIR__ . '/partials/footer.php';
