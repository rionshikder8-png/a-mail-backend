<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function mail_config(): array
{
    return [
        'domain' => config('domain'),
        'smtp' => [
            'host' => (string)env('SMTP_HOST', ''),
            'port' => env_int('SMTP_PORT', 587),
            'security' => strtolower((string)env('SMTP_SECURITY', 'tls')), // tls (STARTTLS) | ssl | none
            'user' => (string)env('SMTP_USER', ''),
            'pass' => (string)env('SMTP_PASS', ''),
            'from_name' => (string)env('SMTP_FROM_NAME', 'A-Mail'),
        ],
        'imap' => [
            'host' => (string)env('IMAP_HOST', ''),
            'port' => env_int('IMAP_PORT', 993),
            'user' => (string)env('IMAP_USER', ''),
            'pass' => (string)env('IMAP_PASS', ''),
        ],
        'inbound_secret' => (string)env('INBOUND_SECRET', ''),
        'dkim' => [
            'selector' => (string)env('DKIM_SELECTOR', 'amail'),
            'key_path' => (string)env('DKIM_PRIVATE_KEY_PATH', ''),
        ],
    ];
}

/** True only when an outbound relay is actually configured. Nothing claims delivery otherwise. */
function smtp_configured(): bool
{
    $c = mail_config()['smtp'];
    return $c['host'] !== '';
}

function imap_configured(): bool
{
    $c = mail_config()['imap'];
    return $c['host'] !== '' && function_exists('imap_open');
}
