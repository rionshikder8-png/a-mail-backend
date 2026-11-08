<?php
declare(strict_types=1);

/** Standard envelope shared with the Flutter app: {success,data,error,meta}. */
function json_out(array $payload, int $status = 200): never
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function ok(mixed $data = null, array $meta = []): never
{
    $out = ['success' => true, 'data' => $data, 'error' => null];
    if ($meta) {
        $out['meta'] = $meta;
    }
    json_out($out);
}

function paged(array $items, int $page, int $perPage, int $total): never
{
    ok($items, [
        'page' => $page,
        'per_page' => $perPage,
        'total' => $total,
        'has_more' => ($page * $perPage) < $total,
    ]);
}

function fail(string $code, string $message, int $status = 400, array $fields = []): never
{
    $err = ['code' => $code, 'message' => $message];
    if ($fields) {
        $err['fields'] = $fields;
    }
    json_out(['success' => false, 'data' => null, 'error' => $err], $status);
}

/** Map any uncaught exception to a safe 500. Details go to the log, never to the client. */
function handle_exception(Throwable $e): never
{
    log_error($e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    $msg = config('debug') ? $e->getMessage() : 'Something went wrong on our side.';
    fail('server_error', $msg, 500);
}

function log_error(string $msg): void
{
    $dir = dirname(__DIR__) . '/storage/logs';
    if (is_dir($dir) && is_writable($dir)) {
        @file_put_contents($dir . '/app-' . gmdate('Y-m-d') . '.log', '[' . gmdate('c') . '] ' . $msg . "\n", FILE_APPEND | LOCK_EX);
    } else {
        error_log('A-Mail: ' . $msg);
    }
}
