<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/services/email_service.php';

require_method('POST');
$u = auth_user();
$uid = (int)$u['id'];
$ids = ids_from_input();
if (!$ids) {
    fail('validation', 'No messages selected.', 422);
}
$until = iso_to_db(input_str('until', '', 40) ?: null);
if ($until !== null && strtotime($until . ' UTC') <= time()) {
    fail('validation', 'Pick a time in the future.', 422, ['until' => 'past']);
}
$owned = owned_email_ids($uid, $ids);
if ($owned) {
    $in = implode(',', array_fill(0, count($owned), '?'));
    q("UPDATE emails SET snoozed_until = ? WHERE user_id = ? AND id IN ($in)", array_merge([$until, $uid], $owned));
}
ok(null);
