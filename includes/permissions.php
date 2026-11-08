<?php
declare(strict_types=1);

/**
 * Role-based access control for the admin panel.
 * Capability map: each capability lists the roles that hold it.
 * Mailbox CONTENT is deliberately not a capability for any role: admins can manage accounts, never read mail.
 */
const ADMIN_CAPS = [
    'dashboard.view'     => ['super_admin', 'admin', 'support', 'moderator'],
    'users.view'         => ['super_admin', 'admin', 'support', 'moderator'],
    'users.manage'       => ['super_admin', 'admin'],
    'users.suspend'      => ['super_admin', 'admin', 'moderator'],
    'users.reset_2fa'    => ['super_admin', 'admin'],
    'users.delete'       => ['super_admin'],
    'mailboxes.view'     => ['super_admin', 'admin', 'support'],
    'domains.manage'     => ['super_admin', 'admin'],
    'logs.view'          => ['super_admin', 'admin', 'support', 'moderator'],
    'spam.manage'        => ['super_admin', 'admin', 'moderator'],
    'abuse.manage'       => ['super_admin', 'admin', 'moderator'],
    'storage.manage'     => ['super_admin', 'admin'],
    'plans.manage'       => ['super_admin', 'admin'],
    'subscriptions.manage' => ['super_admin', 'admin'],
    'notifications.send' => ['super_admin', 'admin'],
    'support.manage'     => ['super_admin', 'admin', 'support'],
    'reports.view'       => ['super_admin', 'admin'],
    'security.view'      => ['super_admin', 'admin'],
    'system.view'        => ['super_admin', 'admin'],
    'settings.manage'    => ['super_admin'],
    'admins.manage'      => ['super_admin'],
];

function admin_can(string $cap, ?string $role = null): bool
{
    $role ??= (string)($_SESSION['admin_role'] ?? '');
    return in_array($role, ADMIN_CAPS[$cap] ?? [], true);
}

function admin_require(string $cap): void
{
    if (!admin_can($cap)) {
        http_response_code(403);
        exit('You do not have permission to open this page.');
    }
}

function admin_log(string $action, ?string $targetType = null, ?int $targetId = null, array $details = []): void
{
    q('INSERT INTO admin_activity_logs (admin_id, action, target_type, target_id, details, ip) VALUES (?,?,?,?,?,?)',
        [$_SESSION['admin_id'] ?? null, $action, $targetType, $targetId, $details ? json_encode($details) : null, client_ip()]);
}
