<?php
declare(strict_types=1);

/** Pure-function tests. No database or web server needed. */
require __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/constants.php';
require_once dirname(__DIR__) . '/config/security.php';
require_once dirname(__DIR__) . '/includes/response.php';
require_once dirname(__DIR__) . '/includes/validation.php';
require_once dirname(__DIR__) . '/includes/mail_helpers.php';
require_once dirname(__DIR__) . '/includes/upload_helpers.php';
require_once dirname(__DIR__) . '/mail/mail-parser.php';
require_once dirname(__DIR__) . '/mail/spam-filter.php';
require_once dirname(__DIR__) . '/mail/domain-authentication.php';
require_once dirname(__DIR__) . '/mail/bounce.php';

T::section('username validation');
T::eq(validate_username('rion.shikder'), null, 'accepts a normal username');
T::eq(validate_username('Rion_99'), null, 'accepts mixed case (normalised later)');
T::check(validate_username('ab') !== null, 'rejects 2 characters');
T::check(validate_username('.bad') !== null, 'rejects leading dot');
T::check(validate_username('bad.') !== null, 'rejects trailing dot');
T::check(validate_username('a..b') !== null, 'rejects doubled dots');
T::check(validate_username('has space') !== null, 'rejects spaces');
T::check(validate_username('admin') !== null, 'rejects reserved name');
T::check(validate_username('support') !== null, 'rejects reserved support');
T::check(validate_username(str_repeat('a', 31)) !== null, 'rejects 31 characters');

T::section('password validation');
T::check(validate_password('short1') !== null, 'rejects short');
T::check(validate_password('allletters') !== null, 'rejects no digit');
T::check(validate_password('12345678') !== null, 'rejects no letter');
T::eq(validate_password('GoodPass123'), null, 'accepts good password');

T::section('header safety');
T::eq(header_safe("Hello\r\nBcc: evil@x.com"), 'Hello Bcc: evil@x.com', 'CR/LF collapsed');

T::section('sanitize_html');
$dirty = '<p onclick="x()">Hi <script>alert(1)</script><a href="javascript:alert(1)">bad</a> <a href="https://ok.example/p">good</a><iframe src="//e"></iframe></p>';
$clean = sanitize_html($dirty);
T::check(!str_contains($clean, '<script'), 'removes script');
T::check(!str_contains($clean, 'onclick'), 'removes event handlers');
T::check(!str_contains($clean, 'javascript:'), 'removes javascript: links');
T::check(!str_contains($clean, '<iframe'), 'removes iframes');
T::check(str_contains($clean, 'https://ok.example/p'), 'keeps https links');

T::section('subject normalisation');
T::eq(normalize_subject('Re: Fwd: RE: Hello'), 'hello', 'strips reply/forward prefixes');

T::section('upload safety');
T::eq(safe_filename('../../etc/passwd'), 'passwd', 'removes path components');
T::check(is_blocked_extension('invoice.exe'), 'blocks .exe');
T::check(is_blocked_extension('shell.php.jpg'), 'blocks double extension containing php');
T::check(is_blocked_extension('x.PHP'), 'blocks uppercase');
T::check(!is_blocked_extension('photo.jpg'), 'allows jpg');
T::check(!is_blocked_extension('report.pdf'), 'allows pdf');

T::section('MIME parsing');
$raw = "From: =?UTF-8?B?QWxpY2U=?= <alice@example.com>\r\nTo: Bob <bob@adverune.store>, carol@adverune.store\r\nSubject: =?UTF-8?Q?Caf=C3=A9_menu?=\r\nMessage-ID: <abc123@example.com>\r\nIn-Reply-To: <prev@example.com>\r\nDate: Sat, 07 Nov 2026 10:00:00 +0000\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"XX\"\r\n\r\n--XX\r\nContent-Type: multipart/alternative; boundary=\"YY\"\r\n\r\n--YY\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\nHello =E2=9C=93 world\r\n--YY\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n<p>Hello <b>world</b></p>\r\n--YY--\r\n--XX\r\nContent-Type: text/plain; name=\"note.txt\"\r\nContent-Disposition: attachment; filename=\"note.txt\"\r\nContent-Transfer-Encoding: base64\r\n\r\n" . base64_encode('attachment body') . "\r\n--XX--\r\n";
$p = parse_raw_mail($raw);
T::eq($p['from']['name'] ?? null, 'Alice', 'decodes encoded sender name');
T::eq($p['from']['address'] ?? null, 'alice@example.com', 'parses sender address');
T::eq(count($p['to'] ?? []), 2, 'parses two recipients');
T::eq($p['subject'] ?? null, 'Café menu', 'decodes Q-encoded subject');
T::eq($p['message_id'] ?? null, 'abc123@example.com', 'extracts Message-ID');
T::eq($p['in_reply_to'] ?? null, 'prev@example.com', 'extracts In-Reply-To');
T::check(str_contains($p['text'] ?? '', 'Hello ✓ world'), 'decodes quoted-printable text');
T::check(str_contains($p['html'] ?? '', '<b>world</b>'), 'extracts html part');
T::eq(count($p['attachments'] ?? []), 1, 'finds one attachment');
T::eq($p['attachments'][0]['file_name'] ?? null, 'note.txt', 'attachment filename');
T::eq($p['attachments'][0]['data'] ?? null, 'attachment body', 'decodes base64 attachment');

T::section('spam filter');
$ham = spam_score(['subject' => 'Lunch tomorrow?', 'text' => 'Are you free at noon?', 'html' => '', 'from' => ['address' => 'friend@example.com'], 'attachments' => [], 'headers' => []], []);
T::check(!$ham['is_spam'], 'normal mail is not spam', json_encode($ham));

T::section('bounce detection');
$dsn = "From: MAILER-DAEMON@mx.example.com\r\nContent-Type: multipart/report; report-type=delivery-status; boundary=\"B\"\r\nSubject: Undelivered\r\n\r\n--B\r\nContent-Type: message/delivery-status\r\n\r\nFinal-Recipient: rfc822; gone@example.com\r\nStatus: 5.1.1\r\nDiagnostic-Code: smtp; 550 User unknown\r\n--B--\r\n";
$b = detect_bounce(parse_raw_mail($dsn), $dsn);
T::eq($b['recipient'] ?? null, 'gone@example.com', 'extracts bounced recipient');
T::eq($b['status'] ?? null, '5.1.1', 'extracts status code');
T::eq(detect_bounce(parse_raw_mail($raw), $raw), null, 'normal mail is not a bounce');

T::section('tokens');
T::eq(strlen(random_token(16)), 32, 'random_token length');
T::check(base64url_decode(base64url("\xff\xfe binary")) === "\xff\xfe binary", 'base64url round-trips binary');
T::eq(strlen(otp_code()), 6, 'otp is 6 digits');

T::finish();
