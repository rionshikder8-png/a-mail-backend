<?php
declare(strict_types=1);

/** Router for `php -S` that mimics the .htaccess rewrites, so CI can test without Apache. */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$root = dirname(__DIR__);
if (preg_match('#^/v1/?$#', $path)) {
    $_GET['route'] = 'health';
    require $root . '/api/index.php';
    return true;
}
if (preg_match('#^/v1/([a-z]+/[a-z\-]+)/?$#', $path, $m)) {
    $_GET['route'] = $m[1];
    require $root . '/api/index.php';
    return true;
}
if ($path === '/mail/inbound.php') {
    require $root . '/mail/inbound.php';
    return true;
}
if (preg_match('#^/admin(/[a-z\-]+\.php)?$#', $path, $m)) {
    $_SERVER['SCRIPT_NAME'] = '/admin' . ($m[1] ?? '/index.php');
    chdir($root . '/admin');
    require $root . '/admin' . ($m[1] ?? '/index.php');
    return true;
}
if (preg_match('#^/assets/#', $path) && is_file($root . $path) && !str_contains($path, '..')) {
    return false; // let the built-in server stream the static file
}
http_response_code(404);
echo 'not found';
return true;
