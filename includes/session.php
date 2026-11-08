<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

/** Secure PHP sessions for the admin panel only (the mobile API is token based). */
function admin_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $secure = config('env') === 'production';
    session_name('amail_admin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/admin',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();
    // idle timeout 30 min, absolute 8 h
    $now = time();
    if (isset($_SESSION['last']) && ($now - $_SESSION['last'] > 1800 || $now - ($_SESSION['born'] ?? $now) > 28800)) {
        admin_session_destroy();
        session_start();
    }
    $_SESSION['last'] = $now;
    $_SESSION['born'] ??= $now;
}

function admin_session_destroy(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool)$p['secure'], (bool)$p['httponly']);
    }
    session_destroy();
}
