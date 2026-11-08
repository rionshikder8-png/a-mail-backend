<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/services/storage_service.php';

require_method('GET');
$u = auth_user();
ok(storage_dto((int)$u['id']));
