<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/config/mail_config.php';
require_once dirname(__DIR__) . '/config/storage_config.php';

/** Real checks only: every value is measured, nothing is assumed healthy. */
function system_health(): array
{
    $h = ['checked_at' => gmdate('c'), 'checks' => []];
    $add = static function (string $name, string $status, string $detail) use (&$h): void {
        $h['checks'][] = ['name' => $name, 'status' => $status, 'detail' => $detail];
    };
    try {
        $t = microtime(true);
        q_val('SELECT 1');
        $add('Database', 'ok', 'Responded in ' . round((microtime(true) - $t) * 1000, 1) . ' ms');
    } catch (Throwable $e) {
        $add('Database', 'fail', 'Query failed');
    }
    foreach (['attachments', 'avatars', 'temporary', 'logs', 'backups'] as $d) {
        $p = storage_path($d);
        $add("Storage: $d", is_dir($p) && is_writable($p) ? 'ok' : 'fail', is_dir($p) ? (is_writable($p) ? 'Writable' : 'Not writable') : 'Missing');
    }
    $free = @disk_free_space(storage_path());
    $total = @disk_total_space(storage_path());
    if ($free !== false && $total) {
        $pct = round($free / $total * 100, 1);
        $add('Disk space', $pct < 5 ? 'fail' : ($pct < 15 ? 'warn' : 'ok'), format_bytes((int)$free) . " free ($pct%)");
    }
    $add('SMTP relay', smtp_configured() ? 'ok' : 'warn', smtp_configured() ? 'Configured: ' . mail_config()['smtp']['host'] : 'Not configured. External delivery is disabled.');
    $add('IMAP polling', imap_configured() ? 'ok' : 'warn', imap_configured() ? 'Configured' : 'Not configured (use the inbound pipe/hook instead)');
    $add('Inbound hook secret', mail_config()['inbound_secret'] !== '' ? 'ok' : 'warn', mail_config()['inbound_secret'] !== '' ? 'Set' : 'INBOUND_SECRET empty: HTTP inbound hook rejects all requests');
    $add('APP_KEY', strlen((string)config('app_key')) >= 32 ? 'ok' : 'fail', strlen((string)config('app_key')) >= 32 ? 'Set' : 'Missing or too short: sign-in tokens cannot be issued');
    $add('DKIM key', mail_config()['dkim']['key_path'] !== '' && is_readable(mail_config()['dkim']['key_path']) ? 'ok' : 'warn', 'Outgoing mail ' . (mail_config()['dkim']['key_path'] !== '' && is_readable(mail_config()['dkim']['key_path']) ? 'is' : 'is NOT') . ' DKIM-signed');
    $add('Push (FCM)', env('FCM_SERVICE_ACCOUNT_PATH', '') !== '' ? 'ok' : 'warn', env('FCM_SERVICE_ACCOUNT_PATH', '') !== '' ? 'Configured' : 'Not configured: notifications stay in-app');
    $add('PHP', version_compare(PHP_VERSION, '8.1.0', '>=') ? 'ok' : 'fail', PHP_VERSION);
    foreach (['pdo_mysql', 'mbstring', 'openssl', 'fileinfo', 'curl'] as $ext) {
        $add("ext-$ext", extension_loaded($ext) ? 'ok' : 'fail', extension_loaded($ext) ? 'Loaded' : 'Missing');
    }
    $queue = q_one('SELECT SUM(status IN ("pending","deferred")) AS waiting, SUM(status = "failed") AS failed FROM mail_queue');
    $add('Outbound queue', ((int)($queue['waiting'] ?? 0)) > 500 ? 'warn' : 'ok', ((int)($queue['waiting'] ?? 0)) . ' waiting, ' . ((int)($queue['failed'] ?? 0)) . ' failed');
    return $h;
}
