<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
admin_guard('users.view');

$per = 25;
$page = page_num();
$search = trim((string)($_GET['q'] ?? ''));
$status = (string)($_GET['status'] ?? '');
$where = ['u.status <> "deleted"'];
$args = [];
if ($search !== '') {
    $like = '%' . addcslashes($search, '%_\\') . '%';
    $where[] = '(u.username LIKE ? OR p.display_name LIKE ? OR p.recovery_email LIKE ?)';
    array_push($args, $like, $like, $like);
}
if (in_array($status, ['active', 'suspended'], true)) {
    $where[] = 'u.status = ?';
    $args[] = $status;
}
$sql = 'FROM users u JOIN user_profiles p ON p.user_id = u.id LEFT JOIN subscription_plans sp ON sp.id = u.plan_id WHERE ' . implode(' AND ', $where);
$total = (int)q_val("SELECT COUNT(*) $sql", $args);
$rows = q_all("SELECT u.id, u.username, u.status, u.created_at, u.last_login_at, p.display_name, COALESCE(sp.name,'Free') AS plan $sql ORDER BY u.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $args);

$title = 'Users';
require __DIR__ . '/partials/header.php';
?>
<div class="toolbar">
  <form method="get">
    <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search username, name or recovery email" aria-label="Search users">
    <select name="status" aria-label="Status"><option value="">All statuses</option><option value="active"<?= $status === 'active' ? ' selected' : '' ?>>Active</option><option value="suspended"<?= $status === 'suspended' ? ' selected' : '' ?>>Suspended</option></select>
    <button class="btn" type="submit">Filter</button>
  </form>
</div>
<div class="card">
<?php if (!$rows): ?><div class="empty"><?= $search || $status ? 'No users match those filters.' : 'No accounts yet. They appear here when people register in the app.' ?></div><?php else: ?>
<div class="tablewrap"><table>
  <thead><tr><th>Address</th><th>Name</th><th>Plan</th><th>Status</th><th>Last sign-in</th><th>Joined</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $u): ?>
    <tr>
      <td><a href="/admin/user-details.php?id=<?= (int)$u['id'] ?>"><?= e($u['username']) ?>@<?= e(config('domain')) ?></a></td>
      <td><?= e($u['display_name']) ?></td><td><?= e($u['plan']) ?></td><td><?= status_badge($u['status']) ?></td>
      <td class="muted"><?= e(ago($u['last_login_at'])) ?></td><td class="muted"><?= e(dt($u['created_at'])) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
<?= pager($total, $per, $page) ?>
<?php endif; ?></div>
<?php require __DIR__ . '/partials/footer.php';
