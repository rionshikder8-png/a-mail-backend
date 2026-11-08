<?php
declare(strict_types=1);

// Root entry point: this host serves the API and admin panel only (no public website).
require_once __DIR__ . '/includes/functions.php';
security_headers(true);
ok(['service' => 'A-Mail by Adverune API', 'version' => AMAIL_VERSION, 'docs' => 'See README.md', 'api_root' => '/v1', 'admin' => '/admin/']);
