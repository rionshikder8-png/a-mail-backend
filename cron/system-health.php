<?php
declare(strict_types=1);

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . "/services/sync_service.php";
require_once dirname(__DIR__) . "/services/storage_service.php";
require_once dirname(__DIR__) . "/includes/notification_helpers.php";

require_once dirname(__DIR__) . "/services/health_service.php";
$h = system_health();
set_setting("last_health_check", json_encode($h));
echo json_encode($h), "\n";
cron_beat("system-health");
