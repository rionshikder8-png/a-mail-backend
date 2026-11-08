<?php
declare(strict_types=1);

/**
 * End-to-end API test against a running server + MySQL (see .github/workflows/php-ci.yml).
 *   php -S 127.0.0.1:8080 tests/router.php
 *   php tests/api_test.php http://127.0.0.1:8080
 */
require __DIR__ . '/bootstrap.php';
T::$base = rtrim($argv[1] ?? 'http://127.0.0.1:8080', '/');
require_once dirname(__DIR__) . '/services/email_service.php';

$sfx = substr(bin2hex(random_bytes(3)), 0, 5);
$alice = "alice$sfx";
$bob = "bobby$sfx";
$pw = 'CorrectHorse42';
$ids = static fn(array $r): array => array_column($r['json']['data'] ?? [], 'id');

T::section('health, routing, headers');
$r = http('GET', '/v1/health');
T::eq($r['status'], 200, 'GET /v1/health is 200');
T::eq($r['json']['success'] ?? null, true, 'envelope has success=true');
T::eq(http('GET', '/v1/nope/missing')['status'], 404, 'unknown endpoint is 404');
T::eq(http('GET', '/v1/emails/inbox')['status'], 401, 'protected endpoint without token is 401');
T::check(str_contains(strtolower($r['headers']), 'x-content-type-options: nosniff'), 'security headers present');

T::section('username availability');
T::eq(http('GET', "/v1/auth/username-available?username=$alice")['json']['data']['available'] ?? null, true, 'new username available');
T::eq(http('GET', '/v1/auth/username-available?username=admin')['json']['data']['available'] ?? null, false, 'reserved username unavailable');

T::section('registration');
$r = http('POST', '/v1/auth/register', ['username' => 'x', 'password' => 'weak', 'display_name' => '']);
T::eq($r['status'], 422, 'invalid registration is 422');
T::check(isset($r['json']['error']['fields']['username'], $r['json']['error']['fields']['password']), 'field errors returned');
$r = http('POST', '/v1/auth/register', ['username' => $alice, 'password' => $pw, 'display_name' => 'Alice Test', 'device_name' => 'ci-phone', 'platform' => 'android']);
T::eq($r['status'], 201, 'register alice is 201', $r['raw']);
$aTok = $r['json']['data']['access_token'] ?? '';
$aRef = $r['json']['data']['refresh_token'] ?? '';
T::check($aTok !== '' && $aRef !== '', 'tokens issued');
T::eq($r['json']['data']['user']['address'] ?? '', "$alice@adverune.store", 'address generated on the mail domain');
T::eq(http('GET', "/v1/auth/username-available?username=$alice")['json']['data']['available'] ?? null, false, 'taken username unavailable');
T::check(in_array(http('POST', '/v1/auth/register', ['username' => $alice, 'password' => $pw, 'display_name' => 'Dup'])['status'], [409, 422], true), 'duplicate username rejected');
$bTok = http('POST', '/v1/auth/register', ['username' => $bob, 'password' => $pw, 'display_name' => 'Bob Test'])['json']['data']['access_token'] ?? '';
T::check($bTok !== '', 'register bob');

T::section('login');
T::eq(http('POST', '/v1/auth/login', ['username' => $alice, 'password' => 'WrongPass99'])['status'], 401, 'wrong password is 401');
T::eq(http('POST', '/v1/auth/login', ['username' => "nobody$sfx", 'password' => $pw])['status'], 401, 'unknown user is 401');
T::eq(http('POST', '/v1/auth/login', ['username' => "$alice@adverune.store", 'password' => $pw, 'device_name' => 'second', 'platform' => 'ios'])['status'], 200, 'login with full address works');

T::section('refresh token rotation and theft detection');
$r = http('POST', '/v1/auth/refresh-token', ['refresh_token' => $aRef]);
T::eq($r['status'], 200, 'refresh succeeds');
$newRef = $r['json']['data']['refresh_token'] ?? '';
T::check($newRef !== '' && $newRef !== $aRef, 'refresh token is rotated');
T::eq(http('POST', '/v1/auth/refresh-token', ['refresh_token' => $aRef])['status'], 401, 'reused old refresh token rejected');
T::eq(http('POST', '/v1/auth/refresh-token', ['refresh_token' => $newRef])['status'], 401, 'reuse revoked the token family');
$aTok = http('POST', '/v1/auth/login', ['username' => $alice, 'password' => $pw, 'device_name' => 'ci-phone', 'platform' => 'android'])['json']['data']['access_token'] ?? '';
T::check($aTok !== '', 'can sign in again');

T::section('forged tokens');
T::eq(http('GET', '/v1/users/profile', null, 'abc.def')['status'], 401, 'garbage token rejected');
$parts = explode('.', $aTok);
T::eq(http('GET', '/v1/users/profile', null, ($parts[0] ?? '') . '.' . strrev($parts[1] ?? ''))['status'], 401, 'tampered signature rejected');

T::section('profile');
T::eq(http('GET', '/v1/users/profile', null, $aTok)['json']['data']['display_name'] ?? '', 'Alice Test', 'profile returns display name');
$r = http('POST', '/v1/users/update-profile', ['display_name' => 'Alice T.', 'recovery_email' => 'alice@example.org'], $aTok);
T::eq($r['json']['data']['display_name'] ?? '', 'Alice T.', 'update display name');
T::eq(http('POST', '/v1/users/update-profile', ['recovery_email' => 'me@adverune.store'], $aTok)['status'], 422, 'recovery email on own domain rejected');

T::section('internal mail');
$r = http('POST', '/v1/emails/send', ['to' => [['address' => "$bob@adverune.store"]], 'subject' => 'Hello Bob', 'body_text' => 'First message', 'body_html' => '<p>First <b>message</b></p><script>x()</script>'], $aTok);
T::eq($r['status'], 201, 'send to local user is 201', $r['raw']);
T::eq($r['json']['data']['status'] ?? '', 'delivered', 'status is delivered');
$r = http('GET', '/v1/emails/inbox', null, $bTok);
T::eq(count($r['json']['data'] ?? []), 1, 'bob has 1 inbox message');
$m0 = $r['json']['data'][0] ?? [];
T::eq($m0['subject'] ?? '', 'Hello Bob', 'subject delivered');
T::eq($m0['is_read'] ?? null, false, 'new mail is unread');
T::eq($m0['from']['address'] ?? '', "$alice@adverune.store", 'sender correct');
$bobMsg = (int)($m0['id'] ?? 0);
$r = http('GET', "/v1/emails/view?id=$bobMsg", null, $bTok);
T::eq($r['status'], 200, 'bob opens the message');
T::check(!str_contains((string)($r['json']['data']['body_html'] ?? ''), '<script'), 'stored html is sanitised');
T::eq(count(http('GET', '/v1/emails/sent', null, $aTok)['json']['data'] ?? []), 1, 'alice has the Sent copy');
T::eq(http('GET', "/v1/emails/view?id=$bobMsg", null, $aTok)['status'], 404, 'alice cannot open bob\'s copy');
T::check(in_array('new_email', array_column(http('GET', '/v1/notifications/list', null, $bTok)['json']['data'] ?? [], 'type'), true), 'bob got a new_email notification');

T::section('reply and threading');
T::eq(http('POST', '/v1/emails/reply', ['in_reply_to' => $bobMsg, 'to' => [['address' => "$alice@adverune.store"]], 'body_text' => 'Hi Alice'], $bTok)['status'], 201, 'reply is 201');
$r = http('GET', '/v1/emails/inbox', null, $aTok);
T::eq($r['json']['data'][0]['subject'] ?? '', 'Re: Hello Bob', 'reply subject prefixed');
$tid = $r['json']['data'][0]['thread_id'] ?? '0';
T::eq(count(http('GET', "/v1/emails/view?thread_id=$tid", null, $aTok)['json']['data'] ?? []), 2, 'thread holds both messages');

T::section('honest delivery states');
T::eq(http('POST', '/v1/emails/send', ['to' => [['address' => "ghost$sfx@adverune.store"]], 'subject' => 'x', 'body_text' => 'x'], $aTok)['json']['data']['status'] ?? '', 'undeliverable', 'unknown local mailbox reported as undeliverable');
$r = http('POST', '/v1/emails/send', ['to' => [['address' => 'someone@example.com']], 'subject' => 'External', 'body_text' => 'hi'], $aTok);
T::check(in_array($r['json']['data']['status'] ?? '', ['queued', 'queued_no_relay'], true), 'external mail is queued, never claimed delivered', $r['raw']);
T::eq(q_val('SELECT status FROM mail_queue WHERE rcpt_address = ? ORDER BY id DESC LIMIT 1', ['someone@example.com']), 'pending', 'queue row is pending');
T::eq(http('POST', '/v1/emails/send', ['to' => [], 'subject' => 'x'], $aTok)['status'], 422, 'no recipients rejected');
T::eq(http('POST', '/v1/emails/send', ['to' => ['not-an-email'], 'subject' => 'x'], $aTok)['status'], 422, 'invalid address rejected');

T::section('flags and folders');
T::eq(http('POST', '/v1/emails/star', ['ids' => [$bobMsg], 'value' => true], $bTok)['status'], 200, 'star ok');
T::eq(count(http('GET', '/v1/folders/starred', null, $bTok)['json']['data'] ?? []), 1, 'starred folder lists it');
http('POST', '/v1/emails/mark-read', ['ids' => [$bobMsg], 'value' => false], $bTok);
T::eq((int)q_val('SELECT is_read FROM emails WHERE id = ?', [$bobMsg]), 0, 'mark unread works');
T::eq(http('POST', '/v1/emails/archive', ['ids' => [$bobMsg]], $bTok)['status'], 200, 'archive ok');
T::check(!in_array((string)$bobMsg, $ids(http('GET', '/v1/emails/inbox', null, $bTok)), true), 'archived mail leaves inbox');
T::check(in_array((string)$bobMsg, $ids(http('GET', '/v1/folders/all-mail', null, $bTok)), true), 'archived mail is in All Mail');
http('POST', '/v1/emails/delete', ['ids' => [$bobMsg]], $bTok);
T::check(in_array((string)$bobMsg, $ids(http('GET', '/v1/folders/trash', null, $bTok)), true), 'deleted mail is in Trash');
http('POST', '/v1/emails/restore', ['ids' => [$bobMsg]], $bTok);
T::check(!in_array((string)$bobMsg, $ids(http('GET', '/v1/folders/trash', null, $bTok)), true), 'restore removes it from Trash');
http('POST', '/v1/emails/star', ['ids' => [$bobMsg], 'value' => false], $aTok);
T::eq((int)q_val('SELECT is_starred FROM emails WHERE id = ?', [$bobMsg]), 1, 'another user cannot change flags');

T::section('snooze and schedule');
T::eq(http('POST', '/v1/emails/snooze', ['ids' => [$bobMsg], 'until' => gmdate('c', time() + 3600)], $bTok)['status'], 200, 'snooze ok');
T::check(!in_array((string)$bobMsg, $ids(http('GET', '/v1/emails/inbox', null, $bTok)), true), 'snoozed mail hidden from inbox');
T::eq(http('POST', '/v1/emails/snooze', ['ids' => [$bobMsg], 'until' => gmdate('c', time() - 60)], $bTok)['status'], 422, 'snooze in the past rejected');
$r = http('POST', '/v1/emails/schedule', ['to' => [['address' => "$bob@adverune.store"]], 'subject' => 'Later', 'body_text' => 'x', 'scheduled_at' => gmdate('c', time() + 7200)], $aTok);
T::eq($r['status'], 201, 'schedule ok', $r['raw']);
T::eq(count(http('GET', '/v1/folders/scheduled', null, $aTok)['json']['data'] ?? []), 1, 'scheduled folder lists it');
T::eq(http('POST', '/v1/emails/schedule', ['to' => [['address' => "$bob@adverune.store"]], 'subject' => 'x', 'scheduled_at' => gmdate('c', time() - 60)], $aTok)['status'], 422, 'scheduling in the past rejected');

T::section('drafts');
$r = http('POST', '/v1/emails/save-draft', ['to' => [['address' => "$bob@adverune.store"]], 'subject' => 'Draft one', 'body_text' => 'wip'], $aTok);
T::eq($r['status'], 200, 'save draft', $r['raw']);
$draftId = (int)($r['json']['data']['id'] ?? 0);
$r = http('POST', '/v1/emails/save-draft', ['draft_id' => $draftId, 'to' => [], 'subject' => 'Draft one v2', 'body_text' => 'wip 2'], $aTok);
T::eq((int)($r['json']['data']['id'] ?? 0), $draftId, 'updating a draft keeps its id');
$r = http('GET', '/v1/emails/drafts', null, $aTok);
T::eq(count($r['json']['data'] ?? []), 1, 'one draft listed');
T::eq($r['json']['data'][0]['subject'] ?? '', 'Draft one v2', 'draft content updated');

T::section('labels');
$r = http('POST', '/v1/labels/create', ['name' => 'Work', 'color' => 4280444927], $bTok);
T::eq($r['status'], 201, 'create label', $r['raw']);
$lid = (int)($r['json']['data']['id'] ?? 0);
T::eq(http('POST', '/v1/labels/create', ['name' => 'Work'], $bTok)['status'], 409, 'duplicate label rejected');
T::eq(http('POST', '/v1/labels/assign', ['ids' => [$bobMsg], 'label_id' => $lid, 'add' => true], $bTok)['status'], 200, 'assign label');
T::eq(count(http('GET', "/v1/emails/inbox?label_id=$lid", null, $bTok)['json']['data'] ?? []), 1, 'filter by label');
T::eq(http('POST', '/v1/labels/assign', ['ids' => [$bobMsg], 'label_id' => $lid], $aTok)['status'], 404, 'cannot use another user\'s label');
T::eq(http('POST', '/v1/labels/delete', ['id' => $lid], $bTok)['status'], 200, 'delete label');

T::section('contacts');
$r = http('POST', '/v1/contacts/create', ['name' => 'Carol', 'email' => 'carol@example.com'], $aTok);
T::eq($r['status'], 201, 'create contact');
$cid = (int)($r['json']['data']['id'] ?? 0);
T::eq(http('POST', '/v1/contacts/create', ['name' => 'C2', 'email' => 'carol@example.com'], $aTok)['status'], 409, 'duplicate contact email');
T::eq(http('POST', '/v1/contacts/create', ['name' => '', 'email' => 'bad'], $aTok)['status'], 422, 'invalid contact rejected');
T::eq(count(http('GET', '/v1/contacts/list', null, $bTok)['json']['data'] ?? []), 0, 'contacts are private');
T::eq(http('POST', '/v1/contacts/delete', ['id' => $cid], $aTok)['status'], 200, 'delete contact');

T::section('search');
T::check(count(http('GET', '/v1/emails/search?q=Hello', null, $bTok)['json']['data'] ?? []) >= 1, 'keyword search finds mail');
T::check(count(http('GET', "/v1/emails/search?from=$alice", null, $bTok)['json']['data'] ?? []) >= 1, 'search by sender');
T::eq(http('GET', '/v1/emails/search?q=' . urlencode("%'; DROP TABLE emails;--"), null, $bTok)['status'], 200, 'SQL-injection style input is harmless');
T::check((int)q_val('SELECT COUNT(*) FROM emails') > 0, 'emails table intact');

T::section('attachments');
$tmp = tempnam(sys_get_temp_dir(), 'att');
file_put_contents($tmp, "hello attachment\n");
$r = http('POST', '/v1/attachments/upload', null, $aTok, [], ['file' => new CURLFile($tmp, 'text/plain', 'notes.txt')]);
T::eq($r['status'], 201, 'upload ok', $r['raw']);
$attId = (int)($r['json']['data']['id'] ?? 0);
$exe = tempnam(sys_get_temp_dir(), 'exe');
file_put_contents($exe, 'MZ');
T::eq(http('POST', '/v1/attachments/upload', null, $aTok, [], ['file' => new CURLFile($exe, 'application/octet-stream', 'virus.exe')])['status'], 422, '.exe upload blocked');
$dbl = tempnam(sys_get_temp_dir(), 'php');
file_put_contents($dbl, '<?php echo 1;');
T::eq(http('POST', '/v1/attachments/upload', null, $aTok, [], ['file' => new CURLFile($dbl, 'image/jpeg', 'pic.php.jpg')])['status'], 422, 'double-extension php upload blocked');
$r = http('GET', "/v1/attachments/download?id=$attId", null, $aTok);
T::eq($r['raw'], "hello attachment\n", 'owner can download');
T::check(str_contains(strtolower($r['headers']), 'content-disposition: attachment'), 'downloads forced as attachment');
T::eq(http('GET', "/v1/attachments/download?id=$attId", null, $bTok)['status'], 404, 'other users cannot download');
T::eq(http('POST', '/v1/emails/send', ['to' => [['address' => "$bob@adverune.store"]], 'subject' => 'With file', 'body_text' => 'see attached', 'attachment_ids' => [$attId]], $aTok)['status'], 201, 'send with attachment');
$withFile = [];
foreach (http('GET', '/v1/emails/inbox', null, $bTok)['json']['data'] ?? [] as $m) {
    if ($m['subject'] === 'With file') {
        $withFile = $m;
    }
}
T::eq(count($withFile['attachments'] ?? []), 1, 'recipient sees the attachment');
$bAtt = (int)($withFile['attachments'][0]['id'] ?? 0);
T::eq(http('GET', "/v1/attachments/download?id=$bAtt", null, $bTok)['raw'], "hello attachment\n", 'recipient downloads own copy');
@unlink($tmp);
@unlink($exe);
@unlink($dbl);

T::section('storage, devices, push');
$r = http('GET', '/v1/storage/usage', null, $aTok);
T::check(($r['json']['data']['quota_bytes'] ?? 0) > 0, 'quota reported');
$r = http('GET', '/v1/devices/list', null, $aTok);
T::check(count($r['json']['data'] ?? []) >= 1, 'devices listed');
T::eq(count(array_filter($r['json']['data'] ?? [], fn($d) => $d['current'])), 1, 'exactly one current device');
T::check(count(http('GET', '/v1/devices/login-history', null, $aTok)['json']['data'] ?? []) >= 1, 'login history present');
T::eq(http('POST', '/v1/notifications/register-device', ['push_token' => "tok_$sfx"], $aTok)['status'], 200, 'register push token');

T::section('two-factor');
T::eq(http('POST', '/v1/auth/two-factor', ['enable' => true], $aTok)['json']['data']['two_factor_enabled'] ?? null, true, 'enable 2FA');
$r = http('POST', '/v1/auth/login', ['username' => $alice, 'password' => $pw]);
T::eq($r['json']['data']['two_factor_required'] ?? null, true, 'login now requires 2FA');
T::check(!str_contains($r['raw'], 'access_token'), 'no tokens before the second factor');
$challenge = $r['json']['data']['challenge_id'] ?? '';
T::eq(http('POST', '/v1/auth/two-factor', ['challenge_id' => $challenge, 'code' => '000000'])['status'], 401, 'wrong 2FA code rejected');
$mail = q_val('SELECT body_text FROM emails WHERE user_id = (SELECT id FROM users WHERE username = ?) AND subject = "Your A-Mail sign-in code" ORDER BY id DESC LIMIT 1', [$alice]);
preg_match('/(\d{6})/', (string)$mail, $m);
T::check(isset($m[1]), 'sign-in code arrives in the user\'s own mailbox');
$r = http('POST', '/v1/auth/two-factor', ['challenge_id' => $challenge, 'code' => $m[1] ?? '']);
T::eq($r['status'], 200, 'correct 2FA code signs in', $r['raw']);
$aTok = $r['json']['data']['access_token'] ?? $aTok;
http('POST', '/v1/auth/two-factor', ['enable' => false], $aTok);

T::section('password reset');
T::eq(http('POST', '/v1/auth/forgot-password', ['identifier' => "ghost$sfx"])['status'], 200, 'unknown account looks identical');
T::eq(http('POST', '/v1/auth/forgot-password', ['identifier' => $bob])['status'], 200, 'forgot-password for real account');
$mail = q_val('SELECT body_text FROM emails WHERE user_id = (SELECT id FROM users WHERE username = ?) AND subject = "Reset your A-Mail password" ORDER BY id DESC LIMIT 1', [$bob]);
preg_match('/(\d{6})/', (string)$mail, $m);
$code = $m[1] ?? '';
T::eq(http('POST', '/v1/auth/verify-otp', ['identifier' => $bob, 'code' => '111111', 'purpose' => 'reset_password'])['status'], 401, 'wrong reset code rejected');
T::eq(http('POST', '/v1/auth/verify-otp', ['identifier' => $bob, 'code' => $code, 'purpose' => 'reset_password'])['status'], 200, 'right code verifies');
T::eq(http('POST', '/v1/auth/reset-password', ['identifier' => $bob, 'code' => $code, 'new_password' => 'weak'])['status'], 422, 'weak new password rejected');
T::eq(http('POST', '/v1/auth/reset-password', ['identifier' => $bob, 'code' => $code, 'new_password' => 'BrandNewPass77'])['status'], 200, 'password reset');
T::eq(http('POST', '/v1/auth/reset-password', ['identifier' => $bob, 'code' => $code, 'new_password' => 'AnotherPass88'])['status'], 401, 'reset code is single-use');
T::eq(http('GET', '/v1/users/profile', null, $bTok)['status'], 401, 'reset signs out existing sessions');
T::eq(http('POST', '/v1/auth/login', ['username' => $bob, 'password' => 'BrandNewPass77'])['status'], 200, 'new password works');
T::eq(http('POST', '/v1/users/change-password', ['current_password' => 'nope', 'new_password' => 'Whatever123'], $aTok)['status'], 401, 'change-password needs current password');

T::section('email verification');
$mail = q_val('SELECT body_text FROM emails WHERE user_id = (SELECT id FROM users WHERE username = ?) AND subject = "Verify your A-Mail account" ORDER BY id DESC LIMIT 1', [$alice]);
preg_match('/(\d{6})/', (string)$mail, $m);
T::eq(http('POST', '/v1/auth/verify-email', ['code' => '123456'], $aTok)['status'], 401, 'wrong verification code');
T::eq(http('POST', '/v1/auth/verify-email', ['code' => $m[1] ?? ''], $aTok)['json']['data']['email_verified'] ?? null, true, 'email verified');

T::section('inbound mail from outside');
$raw = "From: Ext Sender <ext@example.com>\r\nTo: $alice@adverune.store\r\nSubject: From the outside\r\nMessage-ID: <ext-$sfx@example.com>\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nHello from another provider\r\n";
T::eq(inbound_deliver_raw($raw)["$alice@adverune.store"] ?? '', 'delivered', 'inbound message delivered to the local mailbox');
T::eq(inbound_deliver_raw($raw)["$alice@adverune.store"] ?? '', 'duplicate', 'duplicate Message-ID not delivered twice');
T::check(in_array('From the outside', array_column(http('GET', '/v1/emails/inbox', null, $aTok)['json']['data'] ?? [], 'subject'), true), 'external message visible in the app inbox');
$spam = "From: x@spam.example\r\nTo: $alice@adverune.store\r\nSubject: CLAIM YOUR PRIZE!!! 100% FREE\r\nMessage-ID: <spam-$sfx@spam.example>\r\nAuthentication-Results: mx; spf=fail; dmarc=fail\r\n\r\nlottery winner click here to unsubscribe http://1.2.3.4/x\r\n";
T::eq(inbound_deliver_raw($spam)["$alice@adverune.store"] ?? '', 'spam', 'spam is filed to Spam, not Inbox');
T::eq(count(http('GET', '/v1/folders/spam', null, $aTok)['json']['data'] ?? []), 1, 'spam folder holds it');
T::eq(http('POST', '/mail/inbound.php', null, null, ['X-Inbound-Secret: wrong'])['status'], 401, 'inbound HTTP hook rejects a bad secret');

T::section('rate limiting');
$hit = false;
for ($i = 0; $i < 14; $i++) {
    if (http('POST', '/v1/auth/login', ['username' => "rl$sfx", 'password' => 'x'])['status'] === 429) {
        $hit = true;
        break;
    }
}
T::check($hit, 'repeated failed logins are rate limited (429)');

T::section('logout and account deletion');
$tok = http('POST', '/v1/auth/login', ['username' => $alice, 'password' => $pw, 'device_name' => 'out'])['json']['data']['access_token'] ?? '';
T::eq(http('POST', '/v1/auth/logout', [], $tok)['status'], 200, 'logout ok');
T::eq(http('GET', '/v1/users/profile', null, $tok)['status'], 401, 'token dead after logout');
$tok = http('POST', '/v1/auth/login', ['username' => $alice, 'password' => $pw, 'device_name' => 'del'])['json']['data']['access_token'] ?? '';
T::eq(http('POST', '/v1/users/delete-account', ['password' => 'wrong'], $tok)['status'], 401, 'delete requires correct password');
T::eq(http('POST', '/v1/users/delete-account', ['password' => $pw], $tok)['status'], 200, 'delete account');
T::eq((int)q_val('SELECT COUNT(*) FROM users WHERE username = ?', [$alice]), 0, 'account row removed');
T::eq((int)q_val('SELECT COUNT(*) FROM emails WHERE user_id NOT IN (SELECT id FROM users)'), 0, 'no orphaned mail');

T::finish();
