<?php
declare(strict_types=1);

/**
 * Inbound entry points.
 *
 *  1. CLI pipe (recommended with Postfix):  /etc/aliases or virtual alias -> "|php /path/mail/inbound.php"
 *     Reads the raw RFC 822 message on STDIN. Optional first argument = envelope recipient.
 *  2. HTTP hook (for Mailgun/Postmark/SES style relays that POST raw MIME):
 *     POST /mail/inbound.php with header X-Inbound-Secret: <INBOUND_SECRET> and the raw message as body.
 *
 * The library function inbound_process() is used by both and by the IMAP poller.
 */
require_once dirname(__DIR__) . '/services/email_service.php';
require_once __DIR__ . '/bounce.php';

function inbound_process(string $raw, array $envelopeRcpts = []): array
{
    if (strlen($raw) < 20) {
        return ['_error' => 'empty_message'];
    }
    $parsed = parse_raw_mail($raw);
    if (($b = detect_bounce($parsed, $raw)) !== null) {
        apply_bounce($b);
        mail_log('inbound', null, $parsed['from']['address'] ?? null, $b['recipient'], null, strlen($raw), null, 'bounce', $b['status']);
        return ['_bounce' => $b['recipient']];
    }
    return inbound_deliver_raw($raw, $envelopeRcpts);
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $raw = stream_get_contents(STDIN) ?: '';
    $rcpt = isset($argv[1]) ? [strtolower(trim($argv[1]))] : [];
    $res = inbound_process($raw, $rcpt);
    fwrite(STDERR, json_encode($res) . "\n");
    // exit 0 so the MTA does not bounce mail we have already accepted or deliberately rejected
    exit(0);
}

if (PHP_SAPI !== 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    require_once dirname(__DIR__) . '/config/mail_config.php';
    security_headers(true);
    $secret = mail_config()['inbound_secret'];
    $sent = (string)($_SERVER['HTTP_X_INBOUND_SECRET'] ?? '');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        fail('method_not_allowed', 'POST only.', 405);
    }
    if ($secret === '' || !hash_equals($secret, $sent)) {
        fail('unauthorized', 'Invalid inbound secret.', 401);
    }
    $raw = file_get_contents('php://input') ?: '';
    if (strlen($raw) > 60 * 1024 * 1024) {
        fail('payload_too_large', 'Message too large.', 413);
    }
    $rcpt = isset($_SERVER['HTTP_X_ENVELOPE_RCPT']) ? array_map('strtolower', array_map('trim', explode(',', $_SERVER['HTTP_X_ENVELOPE_RCPT']))) : [];
    ok(inbound_process($raw, $rcpt));
}
