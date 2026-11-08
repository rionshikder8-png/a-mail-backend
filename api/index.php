<?php
declare(strict_types=1);

/**
 * Front controller for /v1/<group>/<action>. Apache rewrites the pretty URL to this file with ?route=group/action.
 * Only routes that map to an existing file under api/<group>/ are served; everything else is a 404.
 */
require_once dirname(__DIR__) . '/includes/functions.php';

set_exception_handler('handle_exception');
set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
    if (!(error_reporting() & $no)) {
        return false;
    }
    throw new ErrorException($str, 0, $no, $file, $line);
});

ini_set('display_errors', '0');
security_headers(true);
require_https();
if (config('env') !== 'production') {
    header('Access-Control-Allow-Origin: *'); // dev only; the mobile app is not a browser and does not need CORS
}
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Client');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

const ROUTE_ALIASES = [
    'auth/username-available' => 'auth/username-available',
    'emails/thread' => 'emails/view',
];

$route = trim((string)($_GET['route'] ?? ''), '/');
$route = ROUTE_ALIASES[$route] ?? $route;

if ($route === '' || $route === 'health') {
    ok(['service' => 'a-mail-api', 'version' => AMAIL_VERSION, 'time' => gmdate('c')]);
}

if (!preg_match('#^([a-z]+)/([a-z\-]+)$#', $route, $m)) {
    fail('not_found', 'Unknown endpoint.', 404);
}
[, $group, $action] = $m;
$file = __DIR__ . "/$group/$action.php";
if (!is_file($file) || dirname($file) !== __DIR__ . "/$group") {
    fail('not_found', 'Unknown endpoint.', 404);
}

if (setting('maintenance_mode', '0') === '1' && !in_array($route, ['auth/login'], true)) {
    fail('maintenance', 'A-Mail is undergoing maintenance. Please try again shortly.', 503);
}

require $file;
