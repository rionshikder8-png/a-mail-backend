<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

/**
 * Access tokens are signed, short-lived and self-describing: base64url(payload).base64url(hmac).
 * Payload carries the session id so a revoked/expired session is rejected immediately (checked in DB).
 */
function access_token_issue(int $userId, int $sessionId): array
{
    $exp = time() + (int)config('access_ttl');
    $payload = base64url(json_encode(['u' => $userId, 's' => $sessionId, 'e' => $exp, 'n' => random_token(6)]));
    $sig = base64url(hash_hmac('sha256', $payload, (string)config('app_key'), true));
    return [$payload . '.' . $sig, $exp];
}

function access_token_parse(string $token): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 2 || config('app_key') === '') {
        return null;
    }
    [$payload, $sig] = $parts;
    $expected = base64url(hash_hmac('sha256', $payload, (string)config('app_key'), true));
    if (!hash_equals($expected, $sig)) {
        return null;
    }
    $raw = base64url_decode($payload);
    $data = $raw === false ? null : json_decode($raw, true);
    if (!is_array($data) || ($data['e'] ?? 0) < time()) {
        return null;
    }
    return $data;
}

function bearer_token(): ?string
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($h === '' && function_exists('apache_request_headers')) {
        $all = apache_request_headers();
        $h = $all['Authorization'] ?? $all['authorization'] ?? '';
    }
    return preg_match('/^Bearer\s+(\S+)$/i', $h, $m) ? $m[1] : null;
}

/** Authenticates the request. Returns the user row; fails the request with 401/403 otherwise. */
function auth_user(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $t = bearer_token();
    $data = $t ? access_token_parse($t) : null;
    if (!$data) {
        fail('unauthorized', 'Please sign in again.', 401);
    }
    $row = q_one('SELECT u.id, u.username, u.status, u.email_verified, u.two_factor_enabled, s.id AS session_id, s.device_id
                  FROM sessions s JOIN users u ON u.id = s.user_id
                  WHERE s.id = ? AND s.user_id = ? AND s.revoked_at IS NULL AND s.expires_at > UTC_TIMESTAMP()',
        [(int)$data['s'], (int)$data['u']]);
    if (!$row) {
        fail('unauthorized', 'Your session has ended. Please sign in again.', 401);
    }
    if ($row['status'] !== 'active') {
        fail('account_suspended', 'This account is not active.', 403);
    }
    if ($row['device_id']) {
        q('UPDATE devices SET last_active = UTC_TIMESTAMP(), last_ip = ? WHERE id = ?', [client_ip(), $row['device_id']]);
    }
    return $cached = $row;
}

function require_verified(array $u): void
{
    if (!$u['email_verified'] && setting('require_verification', '0') === '1') {
        fail('email_unverified', 'Verify your recovery email to continue.', 403);
    }
}

function find_device(int $userId, string $name, string $platform): int
{
    $platform = in_array($platform, PLATFORMS, true) ? $platform : 'android';
    $name = mb_substr($name !== '' ? header_safe($name) : 'Unknown device', 0, 120);
    $id = q_val('SELECT id FROM devices WHERE user_id = ? AND device_name = ? AND platform = ? AND revoked_at IS NULL', [$userId, $name, $platform]);
    if ($id) {
        return (int)$id;
    }
    q('INSERT INTO devices (user_id, device_name, platform, last_ip, last_active) VALUES (?,?,?,?,UTC_TIMESTAMP())', [$userId, $name, $platform, client_ip()]);
    return (int)db()->lastInsertId();
}

/** Create a session + first refresh token. Returns the token bundle sent to the client. */
function session_start_for(int $userId, string $deviceName, string $platform): array
{
    return db_tx(function () use ($userId, $deviceName, $platform) {
        $deviceId = find_device($userId, $deviceName, $platform);
        $refresh = random_token(32);
        $family = random_token(16);
        $exp = gmdate('Y-m-d H:i:s', time() + (int)config('refresh_ttl'));
        q('INSERT INTO sessions (user_id, device_id, token_hash, ip, user_agent, expires_at) VALUES (?,?,?,?,?,?)',
            [$userId, $deviceId, token_hash(random_token(16)), client_ip(), mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), $exp]);
        $sid = (int)db()->lastInsertId();
        q('INSERT INTO refresh_tokens (user_id, session_id, family_id, token_hash, expires_at) VALUES (?,?,?,?,?)',
            [$userId, $sid, $family, token_hash($refresh), $exp]);
        [$access, $accessExp] = access_token_issue($userId, $sid);
        q('UPDATE users SET last_login_at = UTC_TIMESTAMP(), failed_logins = 0, locked_until = NULL WHERE id = ?', [$userId]);
        return [
            'access_token' => $access,
            'refresh_token' => $refresh,
            'expires_in' => max(1, $accessExp - time()),
            'token_type' => 'Bearer',
        ];
    });
}

/**
 * Rotate a refresh token. Reuse of an already-used token is treated as theft:
 * the whole family and its session are revoked.
 */
function refresh_rotate(string $presented): array
{
    $hash = token_hash($presented);
    return db_tx(function () use ($hash) {
        $rt = q_one('SELECT * FROM refresh_tokens WHERE token_hash = ? FOR UPDATE', [$hash]);
        if (!$rt) {
            fail('unauthorized', 'Please sign in again.', 401);
        }
        if ($rt['used_at'] !== null || $rt['revoked_at'] !== null) {
            q('UPDATE refresh_tokens SET revoked_at = UTC_TIMESTAMP() WHERE family_id = ? AND revoked_at IS NULL', [$rt['family_id']]);
            q('UPDATE sessions SET revoked_at = UTC_TIMESTAMP() WHERE id = ? AND revoked_at IS NULL', [$rt['session_id']]);
            security_log((int)$rt['user_id'], 'refresh_token_reuse', 'critical', ['session' => (int)$rt['session_id']]);
            fail('unauthorized', 'Your session has ended. Please sign in again.', 401);
        }
        if (strtotime($rt['expires_at'] . ' UTC') < time()) {
            fail('unauthorized', 'Your session has expired. Please sign in again.', 401);
        }
        $sess = q_one('SELECT s.id, u.status FROM sessions s JOIN users u ON u.id = s.user_id WHERE s.id = ? AND s.revoked_at IS NULL', [$rt['session_id']]);
        if (!$sess || $sess['status'] !== 'active') {
            fail('unauthorized', 'Your session has ended. Please sign in again.', 401);
        }
        q('UPDATE refresh_tokens SET used_at = UTC_TIMESTAMP() WHERE id = ?', [$rt['id']]);
        $new = random_token(32);
        q('INSERT INTO refresh_tokens (user_id, session_id, family_id, token_hash, expires_at) VALUES (?,?,?,?,?)',
            [$rt['user_id'], $rt['session_id'], $rt['family_id'], token_hash($new), $rt['expires_at']]);
        [$access, $exp] = access_token_issue((int)$rt['user_id'], (int)$rt['session_id']);
        return ['access_token' => $access, 'refresh_token' => $new, 'expires_in' => max(1, $exp - time()), 'token_type' => 'Bearer'];
    });
}

function session_revoke(int $sessionId): void
{
    q('UPDATE sessions SET revoked_at = UTC_TIMESTAMP() WHERE id = ? AND revoked_at IS NULL', [$sessionId]);
    q('UPDATE refresh_tokens SET revoked_at = UTC_TIMESTAMP() WHERE session_id = ? AND revoked_at IS NULL', [$sessionId]);
}

function sessions_revoke_all(int $userId, ?int $exceptSession = null): void
{
    $ids = q_all('SELECT id FROM sessions WHERE user_id = ? AND revoked_at IS NULL', [$userId]);
    foreach ($ids as $r) {
        if ($exceptSession === null || (int)$r['id'] !== $exceptSession) {
            session_revoke((int)$r['id']);
        }
    }
}

/** Issue a 6-digit OTP. Stores only a hash. Returns [code, challengeId]. Previous unused codes of the purpose are voided. */
function otp_issue(int $userId, string $purpose, bool $withChallenge = false): array
{
    q('UPDATE otp_codes SET consumed_at = UTC_TIMESTAMP() WHERE user_id = ? AND purpose = ? AND consumed_at IS NULL', [$userId, $purpose]);
    $code = otp_code();
    $challenge = $withChallenge ? random_token(16) : null;
    q('INSERT INTO otp_codes (user_id, purpose, code_hash, challenge_id, expires_at) VALUES (?,?,?,?,?)',
        [$userId, $purpose, token_hash($userId . ':' . $purpose . ':' . $code), $challenge, gmdate('Y-m-d H:i:s', time() + (int)config('otp_ttl'))]);
    return [$code, $challenge];
}

/** Verify (and consume) an OTP. 5 wrong attempts burns the code. */
function otp_verify(int $userId, string $purpose, string $code, ?string $challenge = null): bool
{
    return (bool)db_tx(function () use ($userId, $purpose, $code, $challenge) {
        $sql = 'SELECT * FROM otp_codes WHERE user_id = ? AND purpose = ? AND consumed_at IS NULL AND expires_at > UTC_TIMESTAMP()';
        $args = [$userId, $purpose];
        if ($challenge !== null) {
            $sql .= ' AND challenge_id = ?';
            $args[] = $challenge;
        }
        $row = q_one($sql . ' ORDER BY id DESC LIMIT 1 FOR UPDATE', $args);
        if (!$row) {
            return false;
        }
        if ((int)$row['attempts'] >= 5) {
            q('UPDATE otp_codes SET consumed_at = UTC_TIMESTAMP() WHERE id = ?', [$row['id']]);
            return false;
        }
        $good = hash_equals($row['code_hash'], token_hash($userId . ':' . $purpose . ':' . $code));
        if ($good) {
            q('UPDATE otp_codes SET consumed_at = UTC_TIMESTAMP() WHERE id = ?', [$row['id']]);
            return true;
        }
        q('UPDATE otp_codes SET attempts = attempts + 1 WHERE id = ?', [$row['id']]);
        return false;
    });
}

/** Resolve "name" or "name@adverune.store" to a user row. */
function find_user_by_identifier(string $identifier): ?array
{
    $id = strtolower(trim($identifier));
    if (str_contains($id, '@')) {
        [$local, $domain] = explode('@', $id, 2);
        if ($domain !== config('domain')) {
            return null;
        }
        $id = $local;
    }
    if ($id === '') {
        return null;
    }
    return q_one('SELECT u.*, p.display_name, p.recovery_email FROM users u JOIN user_profiles p ON p.user_id = u.id
                  WHERE u.domain_id = ? AND u.username = ? AND u.status <> "deleted"', [primary_domain_id(), $id]);
}

function record_login(?int $userId, string $identifier, bool $success, ?string $device, ?string $reason = null): void
{
    q('INSERT INTO login_history (user_id, identifier, success, ip, user_agent, device_name, reason) VALUES (?,?,?,?,?,?,?)',
        [$userId, mb_substr($identifier, 0, 255), $success ? 1 : 0, client_ip(), mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), $device ? mb_substr($device, 0, 120) : null, $reason]);
}
