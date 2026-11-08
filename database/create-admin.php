<?php
declare(strict_types=1);

/**
 * Create an administrator from the command line (never over HTTP).
 *   php database/create-admin.php you@example.com "Your Name" super_admin
 * The password is read from the terminal without echo, or from ADMIN_PASSWORD for automation.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/includes/functions.php';

[$self, $email, $name, $role] = array_pad($argv, 4, null);
$roles = ['super_admin', 'admin', 'support', 'moderator'];
if (!$email || !$name || !in_array($role ?? 'super_admin', $roles, true)) {
    fwrite(STDERR, "Usage: php database/create-admin.php <email> \"<name>\" [" . implode('|', $roles) . "]\n");
    exit(1);
}
$role = $role ?? 'super_admin';
$email = strtolower(trim($email));
if (!valid_email($email)) {
    fwrite(STDERR, "Invalid email address.\n");
    exit(1);
}
$pw = getenv('ADMIN_PASSWORD') ?: '';
if ($pw === '') {
    fwrite(STDOUT, 'Password (12+ characters): ');
    if (stripos(PHP_OS, 'WIN') !== 0) {
        system('stty -echo');
    }
    $pw = trim((string)fgets(STDIN));
    if (stripos(PHP_OS, 'WIN') !== 0) {
        system('stty echo');
    }
    fwrite(STDOUT, "\n");
}
if (strlen($pw) < 12) {
    fwrite(STDERR, "Password must be at least 12 characters.\n");
    exit(1);
}
try {
    q('INSERT INTO admins (email, name, password_hash, role, status) VALUES (?,?,?,?, "active")', [$email, $name, password_hash_secure($pw), $role]);
} catch (PDOException $e) {
    fwrite(STDERR, $e->getCode() === '23000' ? "An administrator with that email already exists.\n" : "Database error.\n");
    exit(1);
}
fwrite(STDOUT, "Administrator $email created with role $role.\n");
