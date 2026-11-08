<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/services/search_service.php';

require_method('GET');
$u = auth_user();
rate_limit('search:' . $u['id'], 120, 60);
email_search((int)$u['id']);
