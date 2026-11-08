<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/services/email_service.php';

require_method('POST');
$u = auth_user();
require_verified($u);
$when = iso_to_db(input_str('scheduled_at', '', 40) ?: null);
if ($when === null) {
    fail('validation', 'Choose when to send.', 422, ['scheduled_at' => 'required']);
}
$ts = strtotime($when . ' UTC');
if ($ts < time() + 30) {
    fail('validation', 'Pick a time in the future.', 422, ['scheduled_at' => 'past']);
}
if ($ts > time() + 365 * 86400) {
    fail('validation', 'Scheduled messages can be at most one year ahead.', 422, ['scheduled_at' => 'too_far']);
}
rate_limit('send:' . $u['id'], 60, 3600);
json_out(['success' => true, 'data' => email_send($u, compose_payload_from_input(), $when), 'error' => null], 201);
