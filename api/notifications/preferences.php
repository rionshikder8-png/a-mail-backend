<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/services/notification_service.php';

require_method('GET', 'POST');
$u = auth_user();
$uid = (int)$u['id'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = q_val('SELECT settings FROM user_profiles WHERE user_id = ?', [$uid]);
    $s = $raw ? (json_decode((string)$raw, true) ?: []) : [];
    $cur = notification_prefs($uid);
    foreach (['new_mail', 'sound', 'security'] as $k) {
        if (array_key_exists($k, body())) {
            $cur[$k] = input_bool($k);
        }
    }
    $s['notifications'] = $cur;
    q('UPDATE user_profiles SET settings = ? WHERE user_id = ?', [json_encode($s), $uid]);
}
ok(notification_prefs($uid));
