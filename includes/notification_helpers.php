<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once dirname(__DIR__) . '/services/notification_service.php';

/**
 * Push delivery via FCM HTTP v1. Only runs when a service account is configured;
 * otherwise notifications stay in-app and are NOT marked as pushed.
 */
function fcm_configured(): bool
{
    $p = (string)env('FCM_SERVICE_ACCOUNT_PATH', '');
    return $p !== '' && is_readable($p) && env('FCM_PROJECT_ID', '') !== '';
}

function fcm_access_token(): ?string
{
    static $cache = null;
    if ($cache && $cache['exp'] > time() + 60) {
        return $cache['token'];
    }
    $sa = json_decode((string)file_get_contents((string)env('FCM_SERVICE_ACCOUNT_PATH')), true);
    if (!is_array($sa) || empty($sa['private_key']) || empty($sa['client_email'])) {
        return null;
    }
    $now = time();
    $h = base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $c = base64url(json_encode([
        'iss' => $sa['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600,
    ]));
    openssl_sign("$h.$c", $sig, $sa['private_key'], OPENSSL_ALGO_SHA256);
    $jwt = "$h.$c." . base64url($sig);
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_POSTFIELDS => http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt])]);
    $res = json_decode((string)curl_exec($ch), true);
    curl_close($ch);
    if (empty($res['access_token'])) {
        return null;
    }
    $cache = ['token' => $res['access_token'], 'exp' => $now + (int)($res['expires_in'] ?? 3000)];
    return $cache['token'];
}

/** Returns true if FCM accepted the message; false for a failed send; null when push is not configured. */
function fcm_send(string $deviceToken, string $title, string $body, array $data = []): ?bool
{
    if (!fcm_configured()) {
        return null;
    }
    $tok = fcm_access_token();
    if (!$tok) {
        return false;
    }
    $payload = ['message' => [
        'token' => $deviceToken,
        'notification' => ['title' => $title, 'body' => $body],
        'data' => array_map('strval', $data),
        'android' => ['priority' => 'HIGH'],
    ]];
    $ch = curl_init('https://fcm.googleapis.com/v1/projects/' . rawurlencode((string)env('FCM_PROJECT_ID')) . '/messages:send');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload)]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 404 || $code === 400) {
        q('UPDATE devices SET push_token = NULL WHERE push_token = ?', [$deviceToken]); // dead token
    }
    return $code >= 200 && $code < 300;
}

/** Push unsent notifications to the user's devices. Returns number of notifications processed. */
function push_pending(int $limit = 100): int
{
    if (!fcm_configured()) {
        return 0;
    }
    $rows = q_all('SELECT * FROM notifications WHERE pushed_at IS NULL AND created_at > (UTC_TIMESTAMP() - INTERVAL 1 DAY) ORDER BY id LIMIT ' . (int)$limit);
    foreach ($rows as $n) {
        $devs = q_all('SELECT push_token FROM devices WHERE user_id = ? AND revoked_at IS NULL AND push_token IS NOT NULL', [$n['user_id']]);
        foreach ($devs as $d) {
            fcm_send($d['push_token'], $n['title'], $n['body'], ['type' => $n['type'], 'email_id' => $n['email_id'] ?? '']);
        }
        q('UPDATE notifications SET pushed_at = UTC_TIMESTAMP() WHERE id = ?', [$n['id']]);
    }
    return count($rows);
}
