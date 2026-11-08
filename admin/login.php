<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

if (admin_current()) {
    redirect('/admin/dashboard.php');
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $pass = (string)($_POST['password'] ?? '');
    if (!rate_limit_ok('admin-login:ip:' . client_ip(), 15, 900) || !rate_limit_ok('admin-login:id:' . $email, 6, 900)) {
        $error = 'Too many attempts. Please wait a few minutes.';
    }
    if ($error === '') {
        $a = q_one('SELECT * FROM admins WHERE email = ?', [$email]);
        $dummy = '$2y$10$usesomesillystringforsalt.0123456789abcdefghijklmnopqrstuv';
        $ok = password_verify($pass, $a['password_hash'] ?? $dummy);
        $locked = $a && $a['locked_until'] && strtotime($a['locked_until'] . ' UTC') > time();
        if ($a && $ok && $a['status'] === 'active' && !$locked) {
            session_regenerate_id(true);
            $_SESSION['admin_id'] = (int)$a['id'];
            $_SESSION['admin_role'] = $a['role'];
            $_SESSION['born'] = time();
            q('UPDATE admins SET failed_logins = 0, locked_until = NULL, last_login_at = UTC_TIMESTAMP() WHERE id = ?', [$a['id']]);
            admin_log('login');
            redirect('/admin/dashboard.php');
        }
        if ($a) {
            $f = (int)$a['failed_logins'] + 1;
            q('UPDATE admins SET failed_logins = ?, locked_until = ? WHERE id = ?', [$f, $f >= 6 ? gmdate('Y-m-d H:i:s', time() + 900) : null, $a['id']]);
        }
        security_log(null, 'admin_login_failed', 'warning', ['email' => mb_substr($email, 0, 120)]);
        $error = $locked ? 'This account is temporarily locked. Try again later.' : 'Incorrect email or password.';
    }
}
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title>Sign in · A-Mail Admin</title>
<link rel="icon" href="/assets/logo/a_mail_mark.png">
<link rel="stylesheet" href="/assets/css/style.css"><link rel="stylesheet" href="/assets/css/admin.css">
</head><body>
<div class="login"><div class="box">
  <div class="logo"><img src="/assets/logo/a_mail_logo_full.png" alt="A-Mail by Adverune"><small>Administration console</small></div>
  <div class="card"><div class="bd">
    <?php if ($error): ?><div class="alert err" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" autocomplete="on">
      <?= csrf_field() ?>
      <div class="form-row"><label for="email">Email</label><input id="email" name="email" type="email" required autofocus autocomplete="username"></div>
      <div class="form-row"><label for="password">Password</label><input id="password" name="password" type="password" required autocomplete="current-password"></div>
      <button class="btn primary" style="width:100%;justify-content:center" type="submit">Sign in</button>
    </form>
  </div></div>
  <p class="muted" style="text-align:center;margin-top:16px;font-size:12px">Authorised administrators only. Activity is logged.</p>
</div></div>
</body></html>
