<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/auth.php';

require_method('GET', 'POST');
$u = auth_user();
$uid = (int)$u['id'];
$row = q_one('SELECT signature, language, timezone, settings FROM user_profiles WHERE user_id = ?', [$uid]);
$settings = json_decode((string)($row['settings'] ?? ''), true) ?: [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (['signature', 'language', 'timezone'] as $k) {
        if (array_key_exists($k, body())) {
            $v = input_str($k, '', $k === 'signature' ? 2000 : 64);
            if ($k === 'timezone' && !in_array($v, timezone_identifiers_list(), true)) {
                fail('validation', 'Unknown timezone.', 422, ['timezone' => 'invalid']);
            }
            if ($k === 'language' && !preg_match('/^[a-z]{2}(-[A-Za-z]{2})?$/', $v)) {
                fail('validation', 'Unsupported language.', 422, ['language' => 'invalid']);
            }
            q("UPDATE user_profiles SET $k = ? WHERE user_id = ?", [$v, $uid]);
            $row[$k] = $v;
        }
    }
    if (isset(body()['preferences']) && is_array(body()['preferences'])) {
        $allowed = ['undo_send_seconds', 'swipe_left', 'swipe_right', 'conversation_view', 'smart_reply'];
        foreach ($allowed as $k) {
            if (array_key_exists($k, body()['preferences'])) {
                $settings['preferences'][$k] = body()['preferences'][$k];
            }
        }
        q('UPDATE user_profiles SET settings = ? WHERE user_id = ?', [json_encode($settings), $uid]);
    }
}
ok([
    'signature' => $row['signature'] ?? '',
    'language' => $row['language'] ?? 'en',
    'timezone' => $row['timezone'] ?? 'UTC',
    'preferences' => $settings['preferences'] ?? new stdClass(),
]);
