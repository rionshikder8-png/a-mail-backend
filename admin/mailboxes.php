<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
admin_guard('mailboxes.view');

$per = 25;
$page = page_num();
$search = trim((string)($_GET['q'] ?? ''));
$args = [];
$where = '1=1';
if ($search !== '') {
    $where = 'a.address LIKE ?';
    $args[] = '%' . addcslashes($search, '%_\\') . '%';
}
$total = (int)q_val("SELECT COUNT(*) FROM email_addresses a WHERE $where", $args);
// Aggregate counts only: no subjects, bodies or senders are selected.
$rows = q_all("SELECT a.address, a.is_primary, u.id uid, u.status,
        (SELECT COUNT(*) FROM emails e WHERE e.user_id = u.id) AS msgs,
        (SELECT COUNT(*) FROM emails e WHERE e.user_id = u.id AND e.folder = 'inbox' AND e.is_read = 0) AS unread,
        COALESCE(su.mail_bytes + su.attachments_bytes + su.trash_bytes, 0) AS used,
        COALESCE(sq.quota_bytes, 0) AS quota
    FROM email_addresses a JOIN users u ON u.id = a.user_id
    LEFT JOIN storage_usage su ON su.user_id = u.id LEFT JOIN storage_quotas sq ON sq.user_id = u.id
    WHERE $where ORDER BY a.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $args);

$title = 'Mailboxes';
require __DIR__ . '/partials/header.php';
?>
<div class="toolbar"><form method="get"><input type="search" name="q" value="<?= e($search) ?>" placeholder="Search address" aria-label="Search mailboxes"><button class="btn" type="submit">Search</button></form></div>
<div class="card">
<?php if (!$rows): ?><div class="empty">No mailboxes yet.</div><?php else: ?>
<div class="tablewrap"><table><thead><tr><th>Mailbox</th><th>Type</th><th>Status</th><th>Messages</th><th>Unread</th><th>Storage</th></tr></thead><tbody>
<?php foreach ($rows as $r): $pct = $r['quota'] > 0 ? min(100, (int)round($r['used'] / $r['quota'] * 100)) : 0; ?>
  <tr><td><a href="/admin/user-details.php?id=<?= (int)$r['uid'] ?>"><?= e($r['address']) ?></a></td><td><?= $r['is_primary'] ? 'Primary' : 'Alias' ?></td><td><?= status_badge($r['status']) ?></td>
  <td><?= number_format((int)$r['msgs']) ?></td><td><?= number_format((int)$r['unread']) ?></td>
  <td style="min-width:160px"><div class="meter <?= $pct > 90 ? 'danger' : ($pct > 75 ? 'warn' : '') ?>"><i style="width:<?= $pct ?>%"></i></div><span class="muted"><?= e(format_bytes((int)$r['used'])) ?> / <?= e(format_bytes((int)$r['quota'])) ?></span></td></tr>
<?php endforeach; ?></tbody></table></div><?= pager($total, $per, $page) ?>
<?php endif; ?></div>
<?php require __DIR__ . '/partials/footer.php';
