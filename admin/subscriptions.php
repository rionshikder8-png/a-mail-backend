<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
admin_guard('subscriptions.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_only();
    $id = (int)($_POST['id'] ?? 0);
    if (($_POST['action'] ?? '') === 'cancel' && $id) {
        $s = q_one('SELECT user_id FROM subscriptions WHERE id = ? AND status = "active"', [$id]);
        if ($s) {
            $free = q_one('SELECT id, quota_bytes FROM subscription_plans WHERE code = "free"');
            q('UPDATE subscriptions SET status = "cancelled", ends_at = UTC_TIMESTAMP() WHERE id = ?', [$id]);
            if ($free) {
                q('UPDATE users SET plan_id = ? WHERE id = ?', [$free['id'], $s['user_id']]);
                q('INSERT INTO storage_quotas (user_id, quota_bytes) VALUES (?,?) ON DUPLICATE KEY UPDATE quota_bytes = VALUES(quota_bytes)', [$s['user_id'], $free['quota_bytes']]);
            }
            admin_log('subscription.cancel', 'subscription', $id);
            flash('ok', 'Subscription cancelled and account moved to the Free plan.');
        }
    }
    redirect('/admin/subscriptions.php');
}

$per = 30;
$page = page_num();
$status = (string)($_GET['status'] ?? 'active');
$where = in_array($status, ['active', 'cancelled', 'expired', 'pending'], true) ? 's.status = ?' : '1=1';
$args = $where === '1=1' ? [] : [$status];
$total = (int)q_val("SELECT COUNT(*) FROM subscriptions s WHERE $where", $args);
$rows = q_all("SELECT s.*, u.username, p.name AS plan_name, p.price_cents FROM subscriptions s JOIN users u ON u.id = s.user_id JOIN subscription_plans p ON p.id = s.plan_id WHERE $where ORDER BY s.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $args);
$mrr = (int)q_val('SELECT COALESCE(SUM(CASE p.billing_period WHEN "monthly" THEN p.price_cents WHEN "yearly" THEN p.price_cents/12 ELSE 0 END),0) FROM subscriptions s JOIN subscription_plans p ON p.id = s.plan_id WHERE s.status = "active"');
$title = 'Subscriptions';
require __DIR__ . '/partials/header.php';
?>
<div class="alert note">No payment provider is connected. This list reflects plans assigned by administrators; the amount below is list price, not collected revenue.</div>
<div class="grid cols-3" style="margin-bottom:16px">
  <div class="card stat"><div class="k">Active subscriptions</div><div class="v"><?= (int)q_val('SELECT COUNT(*) FROM subscriptions WHERE status = "active"') ?></div></div>
  <div class="card stat"><div class="k">List-price monthly value</div><div class="v">$<?= number_format($mrr / 100, 2) ?></div></div>
  <div class="card stat"><div class="k">Cancelled</div><div class="v"><?= (int)q_val('SELECT COUNT(*) FROM subscriptions WHERE status = "cancelled"') ?></div></div>
</div>
<div class="toolbar"><form method="get"><select name="status" aria-label="Status"><option value="all"<?= $status === 'all' ? ' selected' : '' ?>>All</option><?php foreach (['active', 'pending', 'cancelled', 'expired'] as $s): ?><option value="<?= $s ?>"<?= $status === $s ? ' selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select><button class="btn" type="submit">Filter</button></form></div>
<div class="card">
<?php if (!$rows): ?><div class="empty">No subscriptions in this view.</div><?php else: ?>
<div class="tablewrap"><table><thead><tr><th>Account</th><th>Plan</th><th>Status</th><th>Started</th><th>Ends</th><th>Note</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $s): ?><tr><td><a href="/admin/user-details.php?id=<?= (int)$s['user_id'] ?>"><?= e($s['username']) ?>@<?= e(config('domain')) ?></a></td><td><?= e($s['plan_name']) ?></td><td><?= status_badge($s['status']) ?></td><td class="muted"><?= e(dt($s['started_at'])) ?></td><td class="muted"><?= e($s['ends_at'] ? dt($s['ends_at']) : '—') ?></td><td class="muted"><?= e((string)$s['note']) ?></td>
<td><?php if ($s['status'] === 'active'): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button class="btn sm" type="submit" data-confirm="Cancel this subscription?">Cancel</button></form><?php endif; ?></td></tr>
<?php endforeach; ?></tbody></table></div><?= pager($total, $per, $page) ?><?php endif; ?></div>
<?php require __DIR__ . '/partials/footer.php';
