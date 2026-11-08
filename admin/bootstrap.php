<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/session.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/permissions.php';

set_exception_handler(static function (Throwable $e): void {
    log_error('admin: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><title>Error</title><p style="font-family:sans-serif;padding:2rem">Something went wrong. It has been logged.</p>';
});
ini_set('display_errors', '0');
security_headers(false);
require_https();
admin_session_start();

/** Optional IP allow-list for the whole admin area (ADMIN_ALLOWED_IPS, comma separated). */
function admin_ip_gate(): void
{
    $allowed = config('admin_ips');
    if ($allowed && !in_array(client_ip(), $allowed, true)) {
        http_response_code(403);
        exit('Access from your network is not permitted.');
    }
}
admin_ip_gate();

function admin_current(): ?array
{
    if (empty($_SESSION['admin_id'])) {
        return null;
    }
    static $a = null;
    if ($a === null) {
        $a = q_one('SELECT id, email, name, role, status FROM admins WHERE id = ?', [$_SESSION['admin_id']]);
        if (!$a || $a['status'] !== 'active') {
            admin_session_destroy();
            return null;
        }
        $_SESSION['admin_role'] = $a['role'];
    }
    return $a;
}

function admin_guard(string $cap = 'dashboard.view'): array
{
    $a = admin_current();
    if (!$a) {
        header('Location: /admin/login.php');
        exit;
    }
    admin_require($cap);
    return $a;
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = [$type, $msg];
}

function flash_render(): string
{
    $out = '';
    foreach ($_SESSION['flash'] ?? [] as [$t, $m]) {
        $out .= '<div class="alert ' . e($t) . '" role="status">' . e($m) . '</div>';
    }
    unset($_SESSION['flash']);
    return $out;
}

function post_only(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        exit('Method not allowed');
    }
    csrf_check();
}

function page_num(): int
{
    return max(1, (int)($_GET['page'] ?? 1));
}

function qs(array $over = []): string
{
    return '?' . http_build_query(array_filter(array_merge($_GET, $over), static fn($v) => $v !== '' && $v !== null));
}

function pager(int $total, int $per, int $page): string
{
    $pages = max(1, (int)ceil($total / $per));
    $from = $total ? ($page - 1) * $per + 1 : 0;
    $to = min($total, $page * $per);
    $h = '<div class="pager"><span>' . $from . '–' . $to . ' of ' . number_format($total) . '</span><span class="links">';
    if ($page > 1) {
        $h .= '<a class="btn sm" href="' . e(qs(['page' => $page - 1])) . '">Previous</a>';
    }
    if ($page < $pages) {
        $h .= '<a class="btn sm" href="' . e(qs(['page' => $page + 1])) . '">Next</a>';
    }
    return $h . '</span></div>';
}

function badge(string $text, string $kind = ''): string
{
    return '<span class="badge ' . e($kind) . '">' . e($text) . '</span>';
}

function status_badge(string $s): string
{
    $map = ['active' => 'ok', 'sent' => 'ok', 'delivered' => 'ok', 'resolved' => 'ok', 'closed' => 'ok', 'pending' => 'info', 'open' => 'info', 'investigating' => 'warn',
        'deferred' => 'warn', 'suspended' => 'warn', 'queued' => 'info', 'failed' => 'fail', 'bounced' => 'fail', 'disabled' => 'fail', 'deleted' => 'fail', 'dismissed' => '', 'expired' => '', 'cancelled' => '',
        'ok' => 'ok', 'warn' => 'warn', 'fail' => 'fail', 'spam' => 'warn', 'critical' => 'fail', 'warning' => 'warn', 'info' => 'info'];
    return badge($s, $map[$s] ?? '');
}

function ago(?string $dt): string
{
    if (!$dt) {
        return '—';
    }
    $d = time() - strtotime($dt . ' UTC');
    if ($d < 60) return 'just now';
    if ($d < 3600) return floor($d / 60) . ' min ago';
    if ($d < 86400) return floor($d / 3600) . ' h ago';
    if ($d < 86400 * 30) return floor($d / 86400) . ' d ago';
    return gmdate('M j, Y', strtotime($dt . ' UTC'));
}

function dt(?string $dt): string
{
    return $dt ? gmdate('M j, Y H:i', strtotime($dt . ' UTC')) . ' UTC' : '—';
}
