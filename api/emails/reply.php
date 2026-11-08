<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/services/email_service.php';

require_method('POST');
$u = auth_user();
require_verified($u);
rate_limit('send:' . $u['id'], 60, 3600);
$p = compose_payload_from_input();
if (!$p['in_reply_to_id']) {
    fail('validation', 'in_reply_to is required for a reply.', 422, ['in_reply_to' => 'required']);
}
$orig = q_one('SELECT subject FROM emails WHERE id = ? AND user_id = ?', [$p['in_reply_to_id'], $u['id']]);
if (!$orig) {
    fail('not_found', 'The message you are replying to was not found.', 404);
}
if ($p['subject'] === '') {
    $p['subject'] = preg_match('/^re:/i', $orig['subject']) ? $orig['subject'] : 'Re: ' . $orig['subject'];
}
json_out(['success' => true, 'data' => email_send($u, $p), 'error' => null], 201);
