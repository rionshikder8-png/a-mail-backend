<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/services/notification_service.php';
$admin = admin_guard('support.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_only();
    $id = (int)($_POST['id'] ?? 0);
    $t = q_one('SELECT * FROM support_tickets WHERE id = ?', [$id]);
    if ($t) {
        $reply = trim((string)($_POST['reply'] ?? ''));
        $status = in_array($_POST['status'] ?? '', ['open', 'pending', 'closed'], true) ? $_POST['status'] : $t['status'];
        $priority = in_array($_POST['priority'] ?? '', ['low', 'normal', 'high'], true) ? $_POST['priority'] : $t['priority'];
        q('UPDATE support_tickets SET status = ?, priority = ?, admin_reply = COALESCE(NULLIF(?, ""), admin_reply), assigned_to = ? WHERE id = ?', [$status, $priority, $reply, $admin['id'], $id]);
        if ($reply !== '' && $t['user_id']) {
            notify_user((int)$t['user_id'], 'system', 'Support replied: ' . mb_substr($t['subject'], 0, 100), mb_substr($reply, 0, 400));
        }
        admin_log('ticket.update', 'ticket', $id, ['status' => $status]);
        flash('ok', 'Ticket updated' . ($reply !== '' && $t['user_id'] ? ' and the user was notified.' : '.'));
    }
    redirect('/admin/support.php?id=' . $id);
}

$id = (int)($_GET['id'] ?? 0);
if ($id) {
    $t = q_one('SELECT t.*, u.username FROM support_tickets t LEFT JOIN users u ON u.id = t.user_id WHERE t.id = ?', [$id]);
    if ($t) {
        $title = 'Ticket #' . $t['id'];
        require __DIR__ . '/partials/header.php';
        ?>
<p><a href="/admin/support.php">← All tickets</a></p>
<div class="card"><div class="hd"><h2><?= e($t['subject']) ?></h2><?= status_badge($t['status']) ?></div><div class="bd">
  <dl class="kv"><dt>From</dt><dd><?= e($t['username'] ? $t['username'] . '@' . config('domain') : ($t['contact_email'] ?: 'unknown')) ?></dd><dt>Opened</dt><dd><?= e(dt($t['created_at'])) ?></dd><dt>Priority</dt><dd><?= e($t['priority']) ?></dd></dl>
  <hr style="border:0;border-top:1px solid var(--outline);margin:16px 0"><p style="white-space:pre-wrap"><?= e($t['message']) ?></p>
  <?php if ($t['admin_reply']): ?><div class="alert note"><b>Latest reply</b><br><span style="white-space:pre-wrap"><?= e($t['admin_reply']) ?></span></div><?php endif; ?>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
    <div class="form-row"><label for="reply">Reply<?= $t['user_id'] ? ' (sent to the user as a notification)' : ' (no linked account: reply by email yourself)' ?></label><textarea id="reply" name="reply"></textarea></div>
    <div class="grid cols-3"><div class="form-row"><label>Status</label><select name="status"><?php foreach (['open', 'pending', 'closed'] as $s): ?><option<?= $t['status'] === $s ? ' selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select></div>
    <div class="form-row"><label>Priority</label><select name="priority"><?php foreach (['low', 'normal', 'high'] as $s): ?><option<?= $t['priority'] === $s ? ' selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select></div></div>
    <button class="btn primary" type="submit">Save</button></form></div></div>
<?php
        require __DIR__ . '/partials/footer.php';
        exit;
    }
}

$per = 25;
$page = page_num();
$status = (string)($_GET['status'] ?? 'open');
$where = in_array($status, ['open', 'pending', 'closed'], true) ? 't.status = ?' : '1=1';
$args = $where === '1=1' ? [] : [$status];
$total = (int)q_val("SELECT COUNT(*) FROM support_tickets t WHERE $where", $args);
$rows = q_all("SELECT t.*, u.username FROM support_tickets t LEFT JOIN users u ON u.id = t.user_id WHERE $where ORDER BY FIELD(t.priority,'high','normal','low'), t.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $args);
$title = 'Support';
require __DIR__ . '/partials/header.php';
?>
<div class="toolbar"><form method="get"><select name="status" aria-label="Status"><option value="all"<?= $status === 'all' ? ' selected' : '' ?>>All</option><?php foreach (['open', 'pending', 'closed'] as $s): ?><option value="<?= $s ?>"<?= $status === $s ? ' selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select><button class="btn" type="submit">Filter</button></form></div>
<div class="card">
<?php if (!$rows): ?><div class="empty">No tickets in this view.</div><?php else: ?>
<div class="tablewrap"><table><thead><tr><th>Subject</th><th>From</th><th>Priority</th><th>Status</th><th>Opened</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><a href="/admin/support.php?id=<?= (int)$r['id'] ?>"><?= e($r['subject']) ?></a></td><td><?= e($r['username'] ? $r['username'] . '@' . config('domain') : ($r['contact_email'] ?: '—')) ?></td><td><?= $r['priority'] === 'high' ? badge('high', 'fail') : e($r['priority']) ?></td><td><?= status_badge($r['status']) ?></td><td class="muted"><?= e(ago($r['created_at'])) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?= pager($total, $per, $page) ?><?php endif; ?></div>
<?php require __DIR__ . '/partials/footer.php';
