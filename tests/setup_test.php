<?php
declare(strict_types=1);

/**
 * The setup wizard is a powerful page, so test that it is closed by default.
 *   php -S 127.0.0.1:8081 tests/router.php   (with SETUP_TOKEN set)
 *   php tests/setup_test.php http://127.0.0.1:8081 <token>
 */
require __DIR__ . '/bootstrap.php';
[, $base, $token] = array_pad($argv, 3, '');
T::$base = rtrim($base, '/');

T::section('setup wizard access control');
$r = http('GET', '/setup/');
T::eq($r['status'], 403, 'no token: refused');
T::check(!str_contains($r['raw'], 'Install database'), 'no token: wizard content not shown');
T::eq(http('GET', '/setup/?token=wrong-token-value-0000000000')['status'], 403, 'wrong token: refused');
T::eq(http('POST', '/setup/', null, null, [], ['action' => 'install'])['status'], 403, 'POST without token cannot install');
$r = http('GET', '/setup/?token=' . urlencode($token));
T::check($r['status'] === 200, 'correct token: wizard opens', 'HTTP ' . $r['status'] . ': ' . substr(trim(strip_tags($r['raw'])), 0, 400));
T::check(str_contains($r['raw'], 'Server checks') && str_contains($r['raw'], 'Install database'), 'wizard shows its steps');
T::check(!str_contains($r['raw'], (string)getenv('DB_PASS')) || getenv('DB_PASS') === '', 'wizard never prints the database password');
T::check(str_contains(strtolower($r['headers']), 'x-robots-tag: noindex'), 'wizard is marked noindex');
T::check(str_contains(strtolower($r['headers']), 'cache-control: no-store'), 'wizard is not cached');
T::finish();
