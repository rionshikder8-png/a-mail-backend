<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/upload_helpers.php';

function username_available(string $username): array
{
    $u = strtolower(trim($username));
    if ($err = validate_username($u)) {
        return ['available' => false, 'reason' => $err];
    }
    $exists = q_val('SELECT 1 FROM users WHERE domain_id = ? AND username = ?', [primary_domain_id(), $u]);
    return $exists ? ['available' => false, 'reason' => 'That username is already taken.'] : ['available' => true, 'address' => user_address($u)];
}

/** Create the account, profile, primary address, quota and welcome notification atomically. */
function user_create(string $username, string $password, string $displayName, ?string $recoveryEmail): int
{
    $username = strtolower($username);
    return (int)db_tx(function () use ($username, $password, $displayName, $recoveryEmail) {
        $plan = (int)q_val('SELECT id FROM subscription_plans WHERE code = "free"');
        q('INSERT INTO users (domain_id, username, password_hash, plan_id) VALUES (?,?,?,?)',
            [primary_domain_id(), $username, password_hash_secure($password), $plan ?: null]);
        $uid = (int)db()->lastInsertId();
        q('INSERT INTO user_profiles (user_id, display_name, recovery_email) VALUES (?,?,?)', [$uid, mb_substr($displayName, 0, 100), $recoveryEmail ?: null]);
        q('INSERT INTO email_addresses (user_id, address, is_primary) VALUES (?,?,1)', [$uid, user_address($username)]);
        $quota = $plan ? (int)q_val('SELECT quota_bytes FROM subscription_plans WHERE id = ?', [$plan]) : (int)config('default_quota');
        q('INSERT INTO storage_quotas (user_id, quota_bytes) VALUES (?,?)', [$uid, $quota]);
        q('INSERT INTO storage_usage (user_id) VALUES (?)', [$uid]);
        q('INSERT INTO notifications (user_id, type, title, body) VALUES (?, "system", ?, ?)',
            [$uid, 'Welcome to A-Mail', 'Your address ' . user_address($username) . ' is ready.']);
        return $uid;
    });
}

function user_set_password(int $userId, string $newPassword): void
{
    q('UPDATE users SET password_hash = ?, failed_logins = 0, locked_until = NULL WHERE id = ?', [password_hash_secure($newPassword), $userId]);
}

/** Permanently remove an account and every file it owns. */
function user_purge(int $userId): void
{
    $files = q_all('SELECT storage_path FROM attachments WHERE user_id = ?', [$userId]);
    foreach ($files as $f) {
        delete_attachment_file($f['storage_path']);
    }
    $av = q_val('SELECT avatar_path FROM user_profiles WHERE user_id = ?', [$userId]);
    if ($av) {
        @unlink(storage_path('avatars/' . basename((string)$av)));
    }
    // ON DELETE CASCADE removes mail, labels, contacts, sessions, notifications, etc.
    q('DELETE FROM users WHERE id = ?', [$userId]);
}
