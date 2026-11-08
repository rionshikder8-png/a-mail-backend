<?php
declare(strict_types=1);

/** Parsed JSON body (or form body). Cached; invalid JSON is a 400. */
function body(): array
{
    static $b = null;
    if ($b !== null) {
        return $b;
    }
    $ct = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
    if (str_contains($ct, 'application/json')) {
        $raw = file_get_contents('php://input') ?: '';
        if (strlen($raw) > 4 * 1024 * 1024) {
            fail('payload_too_large', 'Request body is too large.', 413);
        }
        if ($raw === '') {
            return $b = [];
        }
        $j = json_decode($raw, true);
        if (!is_array($j)) {
            fail('invalid_json', 'Request body must be valid JSON.', 400);
        }
        return $b = $j;
    }
    return $b = $_POST;
}

function input(string $key, mixed $default = null): mixed
{
    $b = body();
    if (array_key_exists($key, $b)) {
        return $b[$key];
    }
    return $_GET[$key] ?? $default;
}

function input_str(string $key, string $default = '', int $max = 5000): string
{
    $v = input($key, $default);
    if (!is_scalar($v)) {
        return $default;
    }
    $v = trim((string)$v);
    return mb_substr($v, 0, $max);
}

function input_int(string $key, int $default = 0): int
{
    $v = input($key, $default);
    return is_numeric($v) ? (int)$v : $default;
}

function input_bool(string $key, bool $default = false): bool
{
    $v = input($key, null);
    if ($v === null) {
        return $default;
    }
    if (is_bool($v)) {
        return $v;
    }
    return in_array(strtolower((string)$v), ['1', 'true', 'yes', 'on'], true);
}

function require_method(string ...$methods): void
{
    $m = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($m === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
    if (!in_array($m, $methods, true)) {
        header('Allow: ' . implode(', ', $methods));
        fail('method_not_allowed', 'Method not allowed.', 405);
    }
}

function validate_username(string $u): ?string
{
    $u = strtolower($u);
    if ($u === '' || !preg_match(USERNAME_REGEX, $u)) {
        return 'Use 3 to 30 letters, numbers, dots or underscores. Start and end with a letter or number.';
    }
    if (str_contains($u, '..') || str_contains($u, '__') || str_contains($u, '._') || str_contains($u, '_.')) {
        return 'Dots and underscores cannot be doubled up.';
    }
    if (in_array($u, RESERVED_USERNAMES, true)) {
        return 'That username is reserved.';
    }
    return null;
}

function validate_password(string $p): ?string
{
    if (strlen($p) < 8) {
        return 'Password must be at least 8 characters.';
    }
    if (strlen($p) > 200) {
        return 'Password is too long.';
    }
    if (!preg_match('/[A-Za-z]/', $p) || !preg_match('/\d/', $p)) {
        return 'Password must include letters and numbers.';
    }
    return null;
}

function valid_email(string $e): bool
{
    return strlen($e) <= 254 && filter_var($e, FILTER_VALIDATE_EMAIL) !== false;
}

/** Accepts [{name,address}] or ["a@b.c"]; returns normalised, de-duplicated list. Throws 422 on bad address. */
function parse_recipients(mixed $v, string $field): array
{
    $out = [];
    $seen = [];
    foreach ((is_array($v) ? $v : []) as $r) {
        $addr = is_array($r) ? (string)($r['address'] ?? '') : (string)$r;
        $name = is_array($r) ? trim((string)($r['name'] ?? '')) : '';
        $addr = strtolower(trim($addr));
        if (!valid_email($addr)) {
            fail('validation', "Invalid email address in $field: " . mb_substr($addr, 0, 60), 422, [$field => 'invalid_address']);
        }
        if (isset($seen[$addr])) {
            continue;
        }
        $seen[$addr] = true;
        $out[] = ['name' => mb_substr(preg_replace('/[\r\n]+/', ' ', $name), 0, 190), 'address' => $addr];
    }
    return $out;
}

/** Strip CR/LF so a value can never inject extra headers. */
function header_safe(string $s): string
{
    return trim(preg_replace('/[\r\n\0]+/', ' ', $s));
}

function ids_from_input(): array
{
    $ids = input('ids', []);
    if (!is_array($ids) && ($single = input('id'))) {
        $ids = [$single];
    }
    $out = [];
    foreach ((array)$ids as $id) {
        if (is_numeric($id) && (int)$id > 0) {
            $out[] = (int)$id;
        }
    }
    $out = array_values(array_unique($out));
    if (count($out) > 500) {
        fail('validation', 'Too many items in one request.', 422);
    }
    return $out;
}

function iso_to_db(?string $iso): ?string
{
    if ($iso === null || $iso === '') {
        return null;
    }
    try {
        return (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    } catch (Throwable) {
        fail('validation', 'Invalid date.', 422);
    }
}

function db_to_iso(?string $d): ?string
{
    if ($d === null || $d === '') {
        return null;
    }
    return (new DateTimeImmutable($d, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
}
