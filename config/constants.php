<?php
declare(strict_types=1);

const AMAIL_VERSION = '1.0.0';
const API_VERSION = 'v1';

const SYSTEM_FOLDERS = ['inbox', 'sent', 'drafts', 'spam', 'trash', 'archive'];
const FOLDER_ALIASES = ['all' => 'all', 'allmail' => 'all'];

const USERNAME_REGEX = '/^[a-z0-9](?:[a-z0-9._]{1,28})[a-z0-9]$/';
const RESERVED_USERNAMES = [
    'admin', 'administrator', 'root', 'postmaster', 'abuse', 'webmaster', 'hostmaster', 'mailer-daemon',
    'support', 'hello', 'contact', 'info', 'security', 'noreply', 'no-reply', 'billing', 'help', 'adverune', 'amail', 'a-mail',
];

const OTP_PURPOSES = ['verify_email', 'reset_password', 'two_factor', 'recovery'];
const PLATFORMS = ['android', 'ios', 'web'];
const DEFAULT_PER_PAGE = 25;
const MAX_PER_PAGE = 100;

const BLOCKED_EXTENSIONS = [
    'exe', 'bat', 'cmd', 'com', 'scr', 'pif', 'msi', 'js', 'jse', 'vbs', 'vbe', 'wsf', 'hta', 'jar', 'ps1', 'dll', 'lnk', 'php', 'phtml', 'phar', 'sh',
];
