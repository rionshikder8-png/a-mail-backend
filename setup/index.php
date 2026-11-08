<?php
declare(strict_types=1);

/**
 * One-time web setup for hosting WITHOUT SSH (cPanel shared hosting).
 *
 *  1. Upload the project, create the MySQL database + user in cPanel, copy .env.example to .env and fill DB_* and SETUP_TOKEN.
 *  2. Open https://api.yourdomain/setup/?token=YOUR_SETUP_TOKEN
 *  3. Run the checks, install the database, create the first administrator.
 *  4. Click "Finish and lock". The wizard writes setup/.lock and refuses to run again. Then delete the setup/ folder.
 *
 * Safe by design: no token, no wizard. Locked, no wizard. It never prints secrets.
 */
require_once dirname(__DIR__) . '/config/config.php';

set_exception_handler(static function (Throwable $e): void {
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><meta name="robots" content="noindex"><body style="font-family:system-ui;max-width:640px;margin:10vh auto;padding:0 1rem"><h2>Setup hit an error</h2><p>'
        . htmlspecialchars($e->getMessage(), ENT_QUOTES) . '</p><p style="color:#667">' . htmlspecialchars(basename($e->getFile()) . ':' . $e->getLine(), ENT_QUOTES) . '</p>';
});
set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
    if (!(error_reporting() & $no)) {
        return false;
    }
    throw new ErrorException($str, 0, $no, $file, $line);
});

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; style-src 'unsafe-inline'; frame-ancestors 'none'");
header('X-Frame-Options: DENY');

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function stop(int $code, string $msg): never
{
    http_response_code($code);
    echo '<!doctype html><meta charset="utf-8"><meta name="robots" content="noindex"><title>A-Mail setup</title><body style="font-family:system-ui;max-width:560px;margin:15vh auto;padding:0 1rem"><h2>A-Mail setup</h2><p>' . h($msg) . '</p>';
    exit;
}

if (is_file(__DIR__ . '/.lock')) {
    stop(403, 'Setup is finished and locked. Delete the setup/ folder from your server.');
}
$expected = (string)env('SETUP_TOKEN', '');
if (strlen($expected) < 24) {
    stop(403, 'Set SETUP_TOKEN in your .env file to a random string of at least 24 characters, then reload this page with ?token=THAT_VALUE.');
}
session_name('amail_setup');
session_set_cookie_params(['path' => '/setup', 'httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();
$given = (string)($_GET['token'] ?? $_POST['token'] ?? '');
if (!empty($_SESSION['setup_ok'])) {
    // already authenticated this session
} elseif ($given !== '' && hash_equals($expected, $given)) {
    session_regenerate_id(true);
    $_SESSION['setup_ok'] = true;
    $_SESSION['csrf'] = bin2hex(random_bytes(24));
} else {
    usleep(400000);
    stop(403, 'Missing or wrong setup token.');
}
$csrf = (string)$_SESSION['csrf'];
$action = (string)($_POST['action'] ?? '');
if ($action !== '' && !hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
    stop(419, 'Session expired. Reload the page.');
}

/** @return list<array{0:string,1:bool,2:string}> */
function checks(): array
{
    $r = [];
    $r[] = ['PHP 8.1 or newer', version_compare(PHP_VERSION, '8.1.0', '>='), 'Running ' . PHP_VERSION . '. In cPanel > Select PHP Version choose 8.1 or higher.'];
    foreach (['pdo_mysql', 'mbstring', 'openssl', 'curl', 'fileinfo', 'json'] as $e) {
        $r[] = ["PHP extension: $e", extension_loaded($e), "Enable '$e' in cPanel > Select PHP Version > Extensions."];
    }
    $r[] = ['PHP extension: imap (optional)', extension_loaded('imap'), 'Only needed for the IMAP fallback. Mail piping works without it.'];
    $r[] = ['.env file exists', is_file(dirname(__DIR__) . '/.env'), 'Copy .env.example to .env in the project root.'];
    $r[] = ['APP_KEY set (32+ chars)', strlen((string)env('APP_KEY', '')) >= 32, 'Put a long random value in APP_KEY.'];
    $r[] = ['TOKEN_PEPPER set (32+ chars)', strlen((string)env('TOKEN_PEPPER', '')) >= 32, 'Put a different long random value in TOKEN_PEPPER.'];
    $r[] = ['APP_ENV is production', env('APP_ENV') === 'production', 'Set APP_ENV=production when you go live.'];
    $r[] = ['Served over HTTPS', !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https', 'Enable AutoSSL for the subdomain in cPanel > SSL/TLS Status.'];
    require_once dirname(__DIR__) . '/config/storage_config.php';
    foreach (['attachments', 'avatars', 'temporary', 'logs'] as $d) {
        $p = storage_path($d);
        $r[] = ["Writable: storage/$d", is_dir($p) && is_writable($p), "Set permissions of $p to 755 (or 775)."];
    }
    return $r;
}

function db_try(): array
{
    try {
        require_once dirname(__DIR__) . '/config/database.php';
        $v = (string)db()->query('SELECT VERSION()')->fetchColumn();
        return [true, "Connected. Server version $v."];
    } catch (Throwable $e) {
        return [false, 'Could not connect. Check DB_HOST, DB_NAME, DB_USER and DB_PASS in .env (in cPanel the name and user have your account prefix, e.g. cpuser_amail).'];
    }
}

function net_try(string $host, int $port): bool
{
    $fp = @fsockopen($host, $port, $en, $es, 6);
    if ($fp) {
        fclose($fp);
        return true;
    }
    return false;
}

$msg = '';
$ok = true;
if ($action === 'install') {
    try {
        require_once dirname(__DIR__) . '/config/database.php';
        $pdo = db();
        $out = [];
        foreach (['schema.sql', 'seed.sql'] as $file) {
            $sql = (string)file_get_contents(dirname(__DIR__) . '/database/' . $file);
            $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
            $n = 0;
            foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $stmt) {
                $pdo->exec($stmt);
                $n++;
            }
            $out[] = "$file: $n statements";
        }
        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (name VARCHAR(190) PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
        $done = array_column($pdo->query('SELECT name FROM schema_migrations')->fetchAll(), 'name');
        $files = glob(dirname(__DIR__) . '/database/migrations/*.sql') ?: [];
        sort($files);
        foreach ($files as $f) {
            if (!in_array(basename($f), $done, true)) {
                $pdo->exec((string)file_get_contents($f));
                $pdo->prepare('INSERT INTO schema_migrations (name) VALUES (?)')->execute([basename($f)]);
                $out[] = 'migration ' . basename($f);
            }
        }
        $msg = 'Database installed. ' . implode('; ', $out);
    } catch (Throwable $e) {
        $ok = false;
        $msg = 'Install failed: ' . $e->getMessage();
    }
} elseif ($action === 'admin') {
    require_once dirname(__DIR__) . '/config/database.php';
    require_once dirname(__DIR__) . '/config/security.php';
    require_once dirname(__DIR__) . '/includes/validation.php';
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $name = trim((string)($_POST['name'] ?? ''));
    $pw = (string)($_POST['password'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '' || strlen($pw) < 12) {
        $ok = false;
        $msg = 'Enter a valid email, a name and a password of at least 12 characters.';
    } else {
        try {
            if ((int)db()->query('SELECT COUNT(*) FROM admins')->fetchColumn() > 0) {
                $ok = false;
                $msg = 'An administrator already exists. Create more from the admin panel.';
            } else {
                db()->prepare('INSERT INTO admins (email, name, password_hash, role, status) VALUES (?,?,?,"super_admin","active")')->execute([$email, $name, password_hash_secure($pw)]);
                $msg = "Administrator $email created.";
            }
        } catch (Throwable $e) {
            $ok = false;
            $msg = 'Could not create the administrator. Run "Install database" first.';
        }
    }
} elseif ($action === 'smtptest') {
    require_once dirname(__DIR__) . '/mail/selftest.php';
    $t = smtp_selftest();
    $ok = $t['ok'];
    $msg = ($ok ? 'Mail sending OK: ' : 'Mail sending problem (' . $t['step'] . '): ') . $t['detail'];
} elseif ($action === 'lock') {
    file_put_contents(__DIR__ . '/.lock', gmdate('c'));
    @unlink(__FILE__);
    session_destroy();
    stop(200, 'Setup locked. Now delete the setup/ folder in cPanel File Manager, and remove SETUP_TOKEN from .env.');
}

[$dbOk, $dbMsg] = db_try();
$mailHost = (string)env('SMTP_HOST', '');
$mailPort = (int)env('SMTP_PORT', 465);
$net = [];
if ($mailHost !== '') {
    $net[] = ["Reach SMTP server $mailHost:$mailPort", net_try($mailHost, $mailPort), 'Ask Dorahost to open the port, or use another SMTP port (465 or 587).'];
}
$net[] = ['Outbound port 25 (direct delivery)', net_try('gmail-smtp-in.l.google.com', 25), 'Most shared hosts block this. That is normal: use your host\'s SMTP server instead.'];
$net[] = ['Outbound port 465', net_try('smtp.gmail.com', 465), 'Needed if you use SSL SMTP.'];
$net[] = ['Outbound port 587', net_try('smtp.gmail.com', 587), 'Needed if you use STARTTLS SMTP.'];
$rows = array_merge(checks(), $net);
$dnsHint = 'api.' . (string)config('domain');
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>A-Mail setup</title>
<style>
body{font-family:system-ui,Segoe UI,Roboto,sans-serif;background:#f6f7fb;color:#1b1f2a;margin:0}
main{max-width:760px;margin:0 auto;padding:24px 16px 64px}
h1{font-size:1.5rem;margin:.2rem 0}h2{font-size:1.05rem;margin:1.6rem 0 .6rem}
.card{background:#fff;border:1px solid #e3e6ee;border-radius:12px;padding:16px;margin:12px 0}
.row{display:flex;gap:10px;padding:6px 0;border-bottom:1px solid #f0f1f6;font-size:.92rem}.row:last-child{border:0}
.b{flex:0 0 auto;font-weight:700;width:3.2rem}.pass{color:#0a7d3c}.fail{color:#b3261e}
.hint{color:#667;font-size:.82rem}
input{width:100%;padding:10px;border:1px solid #cfd4e2;border-radius:8px;margin:4px 0 10px;box-sizing:border-box}
button{background:#2540d9;color:#fff;border:0;border-radius:8px;padding:10px 16px;font-weight:600;cursor:pointer}
button.danger{background:#b3261e}.msg{padding:10px 12px;border-radius:8px;margin:12px 0}.msg.ok{background:#e6f5ec}.msg.no{background:#fdeceb}
</style></head><body><main>
<h1>A-Mail by Adverune: server setup</h1>
<p class="hint">One-time wizard. Keep this URL private. It locks itself when you finish.</p>
<?php if ($msg !== ''): ?><div class="msg <?= $ok ? 'ok' : 'no' ?>"><?= h($msg) ?></div><?php endif; ?>

<h2>1. Server checks</h2>
<div class="card">
<?php foreach ($rows as [$label, $pass, $hint]): ?>
<div class="row"><span class="b <?= $pass ? 'pass' : 'fail' ?>"><?= $pass ? 'PASS' : 'FAIL' ?></span><span><?= h($label) ?><?php if (!$pass): ?><br><span class="hint"><?= h($hint) ?></span><?php endif; ?></span></div>
<?php endforeach; ?>
<div class="row"><span class="b <?= $dbOk ? 'pass' : 'fail' ?>"><?= $dbOk ? 'PASS' : 'FAIL' ?></span><span>MySQL connection<br><span class="hint"><?= h($dbMsg) ?></span></span></div>
</div>

<h2>2. Install the database</h2>
<form method="post" class="card"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="install">
<p class="hint">Creates every table and the reference data. Safe to run again.</p><button <?= $dbOk ? '' : 'disabled' ?>>Install database</button></form>

<h2>3. Create the first administrator</h2>
<form method="post" class="card" autocomplete="off"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="admin">
<label>Email<input name="email" type="email" required></label>
<label>Name<input name="name" required></label>
<label>Password (12+ characters)<input name="password" type="password" minlength="12" required></label>
<button <?= $dbOk ? '' : 'disabled' ?>>Create administrator</button></form>

<h2>4. Test sending mail</h2>
<form method="post" class="card"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="smtptest">
<p class="hint">Connects to <?= h($mailHost !== '' ? $mailHost . ':' . $mailPort : 'your SMTP server (SMTP_HOST is empty)') ?> and logs in. Sends nothing.</p><button>Test SMTP login</button></form>

<h2>5. Finish</h2>
<form method="post" class="card"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="lock">
<p class="hint">Locks the wizard and removes this file. Admin panel: <b>/admin/</b> on <?= h($dnsHint) ?>.</p><button class="danger">Finish and lock</button></form>
</main></body></html>
