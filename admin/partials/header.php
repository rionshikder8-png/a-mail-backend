<?php
/** @var string $title @var string $active */
$admin = admin_current();
$icons = [
  'dashboard' => '<path d="M3 13h8V3H3zM13 21h8v-6h-8zM13 3v6h8V3zM3 21h8v-6H3z"/>',
  'users' => '<path d="M17 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M21 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/>',
  'mailboxes' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
  'domains' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
  'logs' => '<path d="M4 6h16M4 12h16M4 18h10"/>',
  'send' => '<path d="m22 2-7 20-4-9-9-4zM22 2 11 13"/>',
  'shield' => '<path d="M12 3 4 6v6c0 5 3.4 8.4 8 9 4.6-.6 8-4 8-9V6z"/>',
  'flag' => '<path d="M5 21V4M5 4h11l-2 4 2 4H5"/>',
  'db' => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>',
  'card' => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
  'bell' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0"/>',
  'help' => '<circle cx="12" cy="12" r="9"/><path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3M12 17h.01"/>',
  'chart' => '<path d="M3 3v18h18M7 15l4-4 3 3 5-6"/>',
  'pulse' => '<path d="M3 12h4l3-8 4 16 3-8h4"/>',
  'gear' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
  'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
  'out' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
];
$nav = [
  'Overview' => [['dashboard.php', 'Dashboard', 'dashboard', 'dashboard.view'], ['reports.php', 'Reports', 'chart', 'reports.view']],
  'Accounts' => [['users.php', 'Users', 'users', 'users.view'], ['mailboxes.php', 'Mailboxes', 'mailboxes', 'mailboxes.view'], ['domains.php', 'Domains', 'domains', 'domains.manage']],
  'Mail' => [['mail-logs.php', 'Mail logs', 'logs', 'logs.view'], ['delivery-logs.php', 'Delivery logs', 'send', 'logs.view'], ['spam-management.php', 'Spam', 'shield', 'spam.manage'], ['abuse-reports.php', 'Abuse reports', 'flag', 'abuse.manage']],
  'Platform' => [['storage-management.php', 'Storage', 'db', 'storage.manage'], ['plans.php', 'Plans', 'card', 'plans.manage'], ['subscriptions.php', 'Subscriptions', 'card', 'subscriptions.manage'], ['notifications.php', 'Notifications', 'bell', 'notifications.send'], ['support.php', 'Support', 'help', 'support.manage']],
  'System' => [['security-center.php', 'Security center', 'shield', 'security.view'], ['system-health.php', 'System health', 'pulse', 'system.view'], ['settings.php', 'Settings', 'gear', 'settings.manage']],
];
$cur = basename($_SERVER['SCRIPT_NAME'] ?? '');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($title ?? 'Admin') ?> · A-Mail Admin</title>
<link rel="icon" href="/assets/logo/a_mail_mark.png">
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body>
<div class="shell">
  <aside class="side" aria-label="Main navigation">
    <div class="brand"><img src="/assets/logo/a_mail_mark.png" alt=""><div><b>A-Mail</b><small>Admin console</small></div></div>
    <nav class="nav">
<?php foreach ($nav as $group => $items):
    $visible = array_filter($items, static fn($i) => admin_can($i[3]));
    if (!$visible) continue; ?>
      <div class="nav-group"><?= e($group) ?></div>
<?php foreach ($visible as [$file, $label, $icon]): ?>
      <a href="/admin/<?= e($file) ?>" class="<?= $cur === $file ? 'active' : '' ?>"<?= $cur === $file ? ' aria-current="page"' : '' ?>><svg viewBox="0 0 24 24" aria-hidden="true"><?= $icons[$icon] ?></svg><?= e($label) ?></a>
<?php endforeach; endforeach; ?>
    </nav>
    <div class="foot">A-Mail by Adverune<br>v<?= e(AMAIL_VERSION) ?></div>
  </aside>
  <div class="main">
    <header class="top">
      <button class="menu-btn" id="menu-btn" aria-label="Open menu">Menu</button>
      <h1><?= e($title ?? '') ?></h1>
      <span class="sp"></span>
      <button class="btn sm" id="theme-toggle" type="button">Theme</button>
      <div class="who">
        <a href="/admin/profile.php" class="avatar" title="My profile"><?= e(strtoupper(mb_substr($admin['name'] ?? 'A', 0, 1))) ?></a>
        <a href="/admin/logout.php" class="btn sm" title="Sign out">Sign out</a>
      </div>
    </header>
    <main class="content">
<?= flash_render() ?>
