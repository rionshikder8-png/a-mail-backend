<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function storage_path(string $sub = ''): string
{
    // STORAGE_PATH lets shared hosting keep private files ABOVE the web root (e.g. /home/USER/amail-storage).
    $custom = (string)(getenv('STORAGE_PATH') ?: '');
    $base = ($custom !== '' && is_dir($custom)) ? rtrim($custom, '/') : dirname(__DIR__) . '/storage';
    return $sub === '' ? $base : $base . '/' . ltrim($sub, '/');
}

const ALLOWED_AVATAR_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
