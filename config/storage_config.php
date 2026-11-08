<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function storage_path(string $sub = ''): string
{
    $base = dirname(__DIR__) . '/storage';
    return $sub === '' ? $base : $base . '/' . ltrim($sub, '/');
}

const ALLOWED_AVATAR_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
