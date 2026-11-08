<?php
declare(strict_types=1);

/**
 * Logs into the admin panel over HTTP and requests every page. Catches fatal errors, broken SQL,
 * missing includes and permission mistakes. Also proves ordinary access control works.
 *   php tests/admin_smoke.php <base-url> <admin-email> <password>
 */
require __DIR__ . '/bootstrap.php';
[, $base, $email, $pass] = array_pad($argv, 4, '');
T::$base = rtrim($base, '/');
$jar = tempnam(sys_get_temp_dir(), 'jar');

function fetch(string $method, string $path, array $post = [], string $jar = ''): array
{
    $ch = curl_init(T::$base . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 30]);
    if ($post) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $res = (string)curl_exec($ch);
    $size = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $out = ['status' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE), 'headers' => substr($res, 0, $size), 'body' => substr($res, $size)];
    curl_close($ch);
    return $out;
}

T::section('access control before sign-in');
foreach (['dashboard', 'users', 'settings', 'system-health'] as $pg) {
    $r = fetch('GET', "/admin/$pg.php", [], $jar);
    T::check($r['status'] === 302 && str_contains(strtolower($r['headers']), 'location: /admin/login.php'), "$pg.php redirects anonymous visitors to login");
}

T::section('login');
$r = fetch('GET', '/admin/login.php', [], $jar);
T::eq($r['status'], 200, 'login page renders');
preg_match('/name="_csrf" value="([a-f0-9]+)"/', $r['body'], $m);
T::check(isset($m[1]), 'login form has a CSRF token');
$bad = fetch('POST', '/admin/login.php', ['email' => $email, 'password' => 'wrong-password', '_csrf' => $m[1] ?? ''], $jar);
T::check(str_contains($bad['body'], 'Incorrect email or password'), 'wrong password shows a generic error');
$noCsrf = fetch('POST', '/admin/login.php', ['email' => $email, 'password' => $pass], $jar);
T::eq($noCsrf['status'], 419, 'login without CSRF token is refused');
$r = fetch('GET', '/admin/login.php', [], $jar);
preg_match('/name="_csrf" value="([a-f0-9]+)"/', $r['body'], $m);
$ok = fetch('POST', '/admin/login.php', ['email' => $email, 'password' => $pass, '_csrf' => $m[1] ?? ''], $jar);
T::check($ok['status'] === 302 && str_contains(strtolower($ok['headers']), 'location: /admin/dashboard.php'), 'correct credentials sign in', substr($ok['body'], 0, 200));

T::section('every admin page renders');
$pages = ['dashboard', 'users', 'mailboxes', 'domains', 'mail-logs', 'delivery-logs', 'spam-management', 'abuse-reports', 'storage-management', 'plans', 'subscriptions', 'notifications', 'support', 'reports', 'security-center', 'system-health', 'settings', 'profile'];
foreach ($pages as $pg) {
    $r = fetch('GET', "/admin/$pg.php", [], $jar);
    $clean = $r['status'] === 200 && !preg_match('/(Fatal error|Parse error|Warning:|Notice:|Deprecated:|Stack trace|Something went wrong)/i', $r['body']);
    T::check($clean, "$pg.php renders (HTTP {$r['status']})", $clean ? '' : substr(strip_tags($r['body']), 0, 300));
    T::check(str_contains($r['body'], 'A-Mail'), "$pg.php uses the shared layout");
}
$r = fetch('GET', '/admin/reports.php?export=csv&days=7', [], $jar);
T::check($r['status'] === 200 && str_contains(strtolower($r['headers']), 'text/csv'), 'CSV export works');
$r = fetch('GET', '/admin/user-details.php?id=999999', [], $jar);
T::check(in_array($r['status'], [302, 404], true) || str_contains($r['body'], 'not found'), 'unknown user id is handled gracefully');

T::section('privacy: admin pages never expose message content');
$seed = random_bytes(4);
require_once dirname(__DIR__) . '/config/database.php';
$any = q_one('SELECT subject FROM emails WHERE subject IS NOT NULL AND subject <> "" LIMIT 1');
if ($any) {
    foreach (['dashboard', 'users', 'mailboxes', 'mail-logs', 'delivery-logs', 'reports'] as $pg) {
        $r = fetch('GET', "/admin/$pg.php", [], $jar);
        T::check(!str_contains($r['body'], htmlspecialchars($any['subject'], ENT_QUOTES)), "$pg.php does not show message subjects");
    }
} else {
    T::check(true, 'no mail present to check (skipped)');
}

T::section('POST actions need CSRF');
$r = fetch('POST', '/admin/settings.php', ['registration_open' => '1'], $jar);
T::eq($r['status'], 419, 'settings change without CSRF is refused');

T::section('system health: cron heartbeat and SMTP test');
$r = fetch('GET', '/admin/system-health.php', [], $jar);
T::check(str_contains($r['body'], 'Scheduled jobs (cron)') && str_contains($r['body'], 'process-outgoing'), 'cron heartbeat table is shown');
T::check(str_contains($r['body'], 'Test SMTP login'), 'SMTP test button is shown');
preg_match('/name="_csrf" value="([a-f0-9]+)"/', $r['body'], $cm);
$r = fetch('POST', '/admin/system-health.php', ['action' => 'smtptest', '_csrf' => $cm[1] ?? ''], $jar);
T::eq($r['status'], 302, 'SMTP test submits and redirects');
$r = fetch('GET', '/admin/system-health.php', [], $jar);
T::check(str_contains($r['body'], 'SMTP_HOST is empty') || str_contains($r['body'], 'Mail sending'), 'SMTP test reports an honest result when no server is configured');
T::eq(fetch('POST', '/admin/system-health.php', ['action' => 'smtptest'], $jar)['status'], 419, 'SMTP test without CSRF is refused');

T::section('sign out');
$r = fetch('GET', '/admin/logout.php', [], $jar);
T::eq($r['status'], 302, 'logout redirects');
$r = fetch('GET', '/admin/dashboard.php', [], $jar);
T::eq($r['status'], 302, 'dashboard is protected again after logout');
@unlink($jar);
T::finish();
