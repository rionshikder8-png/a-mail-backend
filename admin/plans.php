<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
admin_guard('plans.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_only();
    $act = (string)($_POST['action'] ?? '');
    $name = trim((string)($_POST['name'] ?? ''));
    $code = strtolower(trim((string)($_POST['code'] ?? '')));
    $price = max(0, (int)round(((float)($_POST['price'] ?? 0)) * 100));
    $quota = max(1, (int)($_POST['quota_mb'] ?? 0)) * 1048576;
    $maxAtt = max(1, (int)($_POST['max_att_mb'] ?? 20)) * 1048576;
    $period = in_array($_POST['period'] ?? '', ['free', 'monthly', 'yearly'], true) ? $_POST['period'] : 'monthly';
    $features = array_values(array_filter(array_map('trim', explode("\n", (string)($_POST['features'] ?? '')))));
    if ($act === 'create' && $name !== '' && preg_match('/^[a-z0-9_\-]{2,40}$/', $code)) {
        try {
            q('INSERT INTO subscription_plans (code, name, price_cents, billing_period, quota_bytes, max_attachment_bytes, features, sort_order) VALUES (?,?,?,?,?,?,?,?)',
                [$code, $name, $price, $price === 0 ? 'free' : $period, $quota, $maxAtt, json_encode($features), (int)q_val('SELECT COALESCE(MAX(sort_order),0)+1 FROM subscription_plans')]);
            admin_log('plan.create', 'plan', (int)db()->lastInsertId(), ['code' => $code]);
            flash('ok', 'Plan created.');
        } catch (PDOException $e) {
            flash('err', $e->getCode() === '23000' ? 'A plan with that code already exists.' : 'Could not create the plan.');
        }
    } elseif ($act === 'update' && $name !== '') {
        $id = (int)($_POST['id'] ?? 0);
        q('UPDATE subscription_plans SET name = ?, price_cents = ?, billing_period = ?, quota_bytes = ?, max_attachment_bytes = ?, features = ?, is_active = ? WHERE id = ?',
            [$name, $price, $price === 0 ? 'free' : $period, $quota, $maxAtt, json_encode($features), isset($_POST['is_active']) ? 1 : 0, $id]);
        admin_log('plan.update', 'plan', $id);
        flash('ok', 'Plan saved. Existing subscribers keep their current quota until their plan is changed.');
    } else {
        flash('err', 'Check the plan details and try again.');
    }
    redirect('/admin/plans.php');
}

$plans = q_all('SELECT p.*, (SELECT COUNT(*) FROM users u WHERE u.plan_id = p.id AND u.status <> "deleted") AS members FROM subscription_plans p ORDER BY sort_order');
$title = 'Plans';
require __DIR__ . '/partials/header.php';
?>
<div class="alert note">Plans define quota and limits. No payment provider is connected: assigning a paid plan here is a manual administrative action.</div>
<?php foreach ($plans as $p): $f = json_decode((string)$p['features'], true) ?: []; ?>
<div class="card" style="margin-bottom:16px"><div class="hd"><h2><?= e($p['name']) ?> <span class="muted mono"><?= e($p['code']) ?></span></h2><span><?= (int)$p['members'] ?> members <?= $p['is_active'] ? badge('active', 'ok') : badge('hidden') ?></span></div>
<form method="post" class="bd grid cols-3"><?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
  <div class="form-row"><label>Name</label><input type="text" name="name" value="<?= e($p['name']) ?>" required></div>
  <div class="form-row"><label>Price (USD)</label><input type="number" name="price" step="0.01" min="0" value="<?= number_format($p['price_cents'] / 100, 2, '.', '') ?>"></div>
  <div class="form-row"><label>Billing</label><select name="period"><?php foreach (['free', 'monthly', 'yearly'] as $b): ?><option<?= $p['billing_period'] === $b ? ' selected' : '' ?>><?= $b ?></option><?php endforeach; ?></select></div>
  <div class="form-row"><label>Storage (MB)</label><input type="number" name="quota_mb" min="1" value="<?= (int)round($p['quota_bytes'] / 1048576) ?>"></div>
  <div class="form-row"><label>Max attachment (MB)</label><input type="number" name="max_att_mb" min="1" value="<?= (int)round($p['max_attachment_bytes'] / 1048576) ?>"></div>
  <div class="form-row"><label>Visible</label><label style="font-weight:400"><input type="checkbox" name="is_active" <?= $p['is_active'] ? 'checked' : '' ?>> Offer this plan</label></div>
  <div class="form-row" style="grid-column:1/-1"><label>Features (one per line)</label><textarea name="features"><?= e(implode("\n", $f)) ?></textarea></div>
  <div><button class="btn primary" type="submit">Save plan</button></div></form></div>
<?php endforeach; ?>
<div class="card"><div class="hd"><h2>New plan</h2></div>
<form method="post" class="bd grid cols-3"><?= csrf_field() ?><input type="hidden" name="action" value="create">
  <div class="form-row"><label>Code</label><input type="text" name="code" placeholder="business" pattern="[a-z0-9_\-]{2,40}" required></div>
  <div class="form-row"><label>Name</label><input type="text" name="name" required></div>
  <div class="form-row"><label>Price (USD)</label><input type="number" name="price" step="0.01" min="0" value="0.00"></div>
  <div class="form-row"><label>Billing</label><select name="period"><option>monthly</option><option>yearly</option><option>free</option></select></div>
  <div class="form-row"><label>Storage (MB)</label><input type="number" name="quota_mb" min="1" value="5120"></div>
  <div class="form-row"><label>Max attachment (MB)</label><input type="number" name="max_att_mb" min="1" value="25"></div>
  <div class="form-row" style="grid-column:1/-1"><label>Features (one per line)</label><textarea name="features"></textarea></div>
  <div><button class="btn primary" type="submit">Create plan</button></div></form></div>
<?php require __DIR__ . '/partials/footer.php';
