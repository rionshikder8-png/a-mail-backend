<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/notification_helpers.php';
require_once __DIR__ . '/user_service.php';

/**
 * Deliver an OTP/security message. OTPs are sent as real A-Mail messages to the user's own mailbox
 * (always possible) and to the recovery e-mail through the SMTP relay when configured.
 * We never log or return the code to the client.
 */
function send_otp_message(int $userId, string $purpose, string $code): void
{
    require_once dirname(__DIR__) . '/services/email_service.php';
    $titles = [
        'verify_email' => 'Verify your A-Mail account',
        'reset_password' => 'Reset your A-Mail password',
        'two_factor' => 'Your A-Mail sign-in code',
        'recovery' => 'Your A-Mail recovery code',
    ];
    $subject = $titles[$purpose] ?? 'Your A-Mail code';
    $ttl = (int)round((int)config('otp_ttl') / 60);
    ob_start();
    $codeVar = $code;
    $ttlVar = $ttl;
    include dirname(__DIR__) . '/templates/emails/' . ($purpose === 'verify_email' ? 'verification' : ($purpose === 'reset_password' ? 'password-reset' : 'security-alert')) . '.php';
    $html = (string)ob_get_clean();
    $text = "$subject\n\nYour code is $code. It expires in $ttl minutes.\nIf you did not request this, ignore this message.";
    $user = q_one('SELECT u.id, u.username, p.recovery_email FROM users u JOIN user_profiles p ON p.user_id = u.id WHERE u.id = ?', [$userId]);
    if (!$user) {
        return;
    }
    $mid = new_message_id();
    $emailId = mailbox_store($userId, [
        'message_id' => $mid, 'from' => ['name' => 'A-Mail Security', 'address' => 'security@' . config('domain')],
        'to' => [['name' => '', 'address' => user_address($user['username'])]], 'subject' => $subject,
        'html' => $html, 'text' => $text, 'folder' => 'inbox', 'is_read' => false,
    ]);
    notify_user($userId, 'security', $subject, 'Open the message to see your code.', $emailId);
    if (!empty($user['recovery_email']) && smtp_configured_safe()) {
        // external copy to the recovery address goes through the normal queue
        $sys = q_one('SELECT id FROM users WHERE username = "security" AND domain_id = ?', [primary_domain_id()]);
        $ownerId = $sys ? (int)$sys['id'] : $userId;
        $copy = mailbox_store($ownerId, [
            'message_id' => new_message_id(), 'from' => ['name' => 'A-Mail Security', 'address' => 'security@' . config('domain')],
            'to' => [['name' => '', 'address' => strtolower($user['recovery_email'])]], 'subject' => $subject,
            'html' => $html, 'text' => $text, 'folder' => 'sent', 'is_read' => true, 'sent_state' => 'queued',
        ]);
        q('INSERT INTO mail_queue (email_id, user_id, direction, rcpt_address, status) VALUES (?,?, "outbound", ?, "pending")', [$copy, $ownerId, strtolower($user['recovery_email'])]);
    }
}

function login_attempt(string $identifier, string $password, string $deviceName, string $platform): array
{
    $ip = client_ip();
    rate_limit('login:ip:' . $ip, 30, 600);
    rate_limit('login:id:' . strtolower($identifier), 10, 600);
    $user = find_user_by_identifier($identifier);

    // Always run a password hash check so timing does not reveal whether the account exists.
    $dummy = '$2y$10$usesomesillystringforsalt.0123456789abcdefghijklmnopqrstuv';
    $hash = $user['password_hash'] ?? $dummy;
    $valid = password_verify($password, $hash);

    if (!$user || !$valid) {
        record_login($user ? (int)$user['id'] : null, $identifier, false, $deviceName, $user ? 'bad_password' : 'unknown_user');
        if ($user) {
            $fails = (int)$user['failed_logins'] + 1;
            $lock = $fails >= 8 ? gmdate('Y-m-d H:i:s', time() + 900) : null;
            q('UPDATE users SET failed_logins = ?, locked_until = ? WHERE id = ?', [$fails, $lock, $user['id']]);
            if ($lock) {
                security_log((int)$user['id'], 'account_locked', 'warning');
            }
        }
        fail('invalid_credentials', 'Incorrect username or password.', 401);
    }
    if ($user['locked_until'] && strtotime($user['locked_until'] . ' UTC') > time()) {
        record_login((int)$user['id'], $identifier, false, $deviceName, 'locked');
        fail('account_locked', 'Too many failed attempts. Try again in a few minutes.', 423);
    }
    if ($user['status'] === 'suspended') {
        record_login((int)$user['id'], $identifier, false, $deviceName, 'suspended');
        fail('account_suspended', 'This account has been suspended. Contact support.', 403);
    }
    if (password_needs_rehash($user['password_hash'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT)) {
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash_secure($password), $user['id']]);
    }
    return $user;
}
