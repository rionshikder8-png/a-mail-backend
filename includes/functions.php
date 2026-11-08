<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/config/security.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/validation.php';

function now_utc(): string
{
    return gmdate('Y-m-d H:i:s');
}

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function setting(string $key, string $default = ''): string
{
    static $cache = [];
    if (!array_key_exists($key, $cache)) {
        $v = q_val('SELECT setting_value FROM system_settings WHERE setting_key = ?', [$key]);
        $cache[$key] = $v === null ? $default : (string)$v;
    }
    return $cache[$key];
}

function set_setting(string $key, string $value): void
{
    q('INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)', [$key, $value]);
}

function primary_domain_id(): int
{
    static $id = null;
    if ($id === null) {
        $id = (int)q_val('SELECT id FROM domains WHERE name = ?', [config('domain')]);
        if ($id === 0) {
            q('INSERT INTO domains (name, is_primary, status) VALUES (?, 1, "active")', [config('domain')]);
            $id = (int)db()->lastInsertId();
        }
    }
    return $id;
}

function user_address(string $username): string
{
    return strtolower($username) . '@' . config('domain');
}

function security_log(?int $userId, string $event, string $severity = 'info', array $details = []): void
{
    try {
        q('INSERT INTO security_logs (user_id, event, severity, ip, details) VALUES (?,?,?,?,?)',
            [$userId, $event, $severity, client_ip(), $details ? json_encode($details) : null]);
    } catch (Throwable $e) {
        log_error('security_log failed: ' . $e->getMessage());
    }
}

/**
 * Fixed-window rate limiter backed by MySQL. Returns normally if allowed; otherwise 429.
 * $key should combine the action and the actor (ip, user, identifier).
 */
function rate_limit(string $key, int $max, int $windowSeconds): void
{
    if (!rate_limit_ok($key, $max, $windowSeconds)) {
        $window = intdiv(time(), $windowSeconds) * $windowSeconds;
        header('Retry-After: ' . max(1, ($window + $windowSeconds) - time()));
        fail('rate_limited', 'Too many attempts. Please try again later.', 429);
    }
}

/** Same counter as rate_limit() but returns false instead of ending the request (used by HTML pages). */
function rate_limit_ok(string $key, int $max, int $windowSeconds): bool
{
    $bucket = hash('sha256', $key);
    $window = intdiv(time(), $windowSeconds) * $windowSeconds;
    q('INSERT INTO rate_limits (bucket, window_start, hits) VALUES (?,?,1) ON DUPLICATE KEY UPDATE hits = hits + 1', [$bucket, $window]);
    return (int)q_val('SELECT hits FROM rate_limits WHERE bucket = ? AND window_start = ?', [$bucket, $window]) <= $max;
}

function user_dto(int $userId): array
{
    $u = q_one('SELECT u.id, u.username, u.email_verified, u.two_factor_enabled, p.display_name, p.avatar_path, p.recovery_email, p.recovery_phone,
                       COALESCE(sp.code, "free") AS plan
                FROM users u
                JOIN user_profiles p ON p.user_id = u.id
                LEFT JOIN subscription_plans sp ON sp.id = u.plan_id
                WHERE u.id = ?', [$userId]);
    if (!$u) {
        fail('not_found', 'Account not found.', 404);
    }
    return [
        'id' => (string)$u['id'],
        'username' => $u['username'],
        'address' => user_address($u['username']),
        'display_name' => $u['display_name'],
        'avatar_url' => $u['avatar_path'] ? rtrim((string)config('url'), '/') . '/v1/users/avatar?id=' . $u['id'] : null,
        'recovery_email' => $u['recovery_email'],
        'recovery_phone' => $u['recovery_phone'],
        'email_verified' => (bool)$u['email_verified'],
        'two_factor_enabled' => (bool)$u['two_factor_enabled'],
        'plan' => $u['plan'],
    ];
}

function format_bytes(int|float $b): string
{
    if ($b < 1024) {
        return (int)$b . ' B';
    }
    if ($b < 1048576) {
        return round($b / 1024, 1) . ' KB';
    }
    if ($b < 1073741824) {
        return round($b / 1048576, 1) . ' MB';
    }
    return round($b / 1073741824, 2) . ' GB';
}
