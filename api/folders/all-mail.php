<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/services/email_service.php';

require_method('GET');
$u = auth_user();
email_list((int)$u['id'], 'all');
