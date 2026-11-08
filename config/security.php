<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function security_headers(bool $api = true): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('Cross-Origin-Resource-Policy: same-origin');
    if (config('env') === 'production') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
    if ($api) {
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
    } else {
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; frame-ancestors 'none'; form-action 'self'");
    }
}

function require_https(): void
{
    if (config('env') !== 'production') {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    if (!$https) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'data' => null, 'error' => ['code' => 'https_required', 'message' => 'HTTPS is required.']]);
        exit;
    }
}

function client_ip(): string
{
    // Only trust X-Forwarded-For when explicitly behind a known proxy.
    if (env_bool('TRUST_PROXY', false) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function random_token(int $bytes = 32): string
{
    return bin2hex(random_bytes($bytes));
}

/** Server-side hash for opaque tokens (refresh, reset, session). Pepper adds a secret not stored in the DB. */
function token_hash(string $token): string
{
    return hash_hmac('sha256', $token, (string)config('pepper'));
}

function otp_code(): string
{
    return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function password_hash_secure(string $pw): string
{
    $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    return password_hash($pw, $algo);
}

function base64url(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function base64url_decode(string $s): string|false
{
    return base64_decode(strtr($s, '-_', '+/'), true);
}
