#!/usr/local/bin/php -q
<?php
declare(strict_types=1);

/**
 * cPanel mail pipe.  cPanel > Email > Forwarders > "Add Domain Forwarder" is NOT what you want; instead use
 * Email > Default Address (catch-all) for adverune.store and choose "Pipe to a Program":
 *
 *       amail/mail/pipe.php            (path is relative to your home directory)
 *
 * Exim passes the raw message on STDIN and the real recipient in $LOCAL_PART@$DOMAIN.
 * This file must be executable (chmod 755). It never prints to STDOUT and always exits 0 for mail we
 * have handled, so Exim does not bounce it back to the sender.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/inbound.php';

$raw = stream_get_contents(STDIN) ?: '';
$rcpts = [];
$local = getenv('LOCAL_PART');
$domain = getenv('DOMAIN');
if ($local && $domain) {
    $rcpts[] = strtolower($local . '@' . $domain);
}
if (isset($argv[1]) && str_contains($argv[1], '@')) {
    $rcpts[] = strtolower(trim($argv[1]));
}
try {
    $res = inbound_process($raw, array_values(array_unique($rcpts)));
    $line = json_encode($res);
} catch (Throwable $e) {
    // Never lose mail: keep a copy for retry, and tell Exim to defer (exit 75 = temporary failure).
    require_once dirname(__DIR__) . '/config/storage_config.php';
    $dir = storage_path('temporary');
    if (is_dir($dir) && is_writable($dir)) {
        @file_put_contents($dir . '/inbound-failed-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.eml', $raw);
    }
    fwrite(STDERR, 'inbound error: ' . $e->getMessage() . "\n");
    exit(75);
}
fwrite(STDERR, $line . "\n");

// If the mail was addressed only to mailboxes that do not exist, reject it so the sender gets an honest bounce
// (EX_NOUSER = 67) instead of the message silently vanishing.
$outcomes = array_filter($res, 'is_string');
if ($outcomes && count(array_unique($outcomes)) === 1 && reset($outcomes) === 'no_such_mailbox') {
    exit(67);
}
exit(0);
