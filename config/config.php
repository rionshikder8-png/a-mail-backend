<?php
declare(strict_types=1);

require_once __DIR__ . '/constants.php';

/**
 * Minimal .env loader. Real environment variables win over the file so that
 * production can inject secrets without a file on disk.
 */
function env_load(string $path): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;
    if (!is_readable($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0]) {
            $v = substr($v, 1, -1);
        }
        if (getenv($k) === false) {
            $_ENV[$k] = $v;
            putenv("$k=$v");
        }
    }
}

function env(string $key, ?string $default = null): ?string
{
    $v = getenv($key);
    if ($v === false) {
        return $default;
    }
    return $v;
}

function env_bool(string $key, bool $default = false): bool
{
    $v = env($key);
    return $v === null ? $default : in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
}

function env_int(string $key, int $default): int
{
    $v = env($key);
    return ($v !== null && $v !== '' && is_numeric($v)) ? (int)$v : $default;
}

env_load(dirname(__DIR__) . '/.env');

function config(string $key, mixed $default = null): mixed
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = [
            'env' => env('APP_ENV', 'production'),
            'debug' => env_bool('APP_DEBUG', false),
            'url' => rtrim((string)env('APP_URL', ''), '/'),
            'domain' => strtolower((string)env('MAIL_DOMAIN', 'adverune.store')),
            'app_key' => (string)env('APP_KEY', ''),
            'pepper' => (string)env('TOKEN_PEPPER', ''),
            'access_ttl' => env_int('ACCESS_TOKEN_TTL', 900),
            'refresh_ttl' => env_int('REFRESH_TOKEN_TTL', 2592000),
            'otp_ttl' => env_int('OTP_TTL', 600),
            'default_quota' => env_int('DEFAULT_QUOTA_BYTES', 1073741824),
            'max_attachment' => env_int('MAX_ATTACHMENT_BYTES', 20971520),
            'admin_ips' => array_values(array_filter(array_map('trim', explode(',', (string)env('ADMIN_ALLOWED_IPS', ''))))),
            'root' => dirname(__DIR__),
        ];
    }
    return $cfg[$key] ?? $default;
}
