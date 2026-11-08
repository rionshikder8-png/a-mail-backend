<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/services/email_service.php';

require_method('POST');
$u = auth_user();
require_verified($u);
rate_limit('send:' . $u['id'], 60, 3600);
$res = email_send($u, compose_payload_from_input());
$msg = match ($res['status']) {
    'delivered' => 'Message delivered.',
    'queued' => 'Message queued for delivery.',
    'queued_no_relay' => 'Saved. External delivery is waiting for the mail relay to be configured.',
    'undeliverable' => 'No matching A-Mail account was found for the recipient.',
    default => 'Message processed.',
};
json_out(['success' => true, 'data' => $res + ['message' => $msg], 'error' => null], 201);
