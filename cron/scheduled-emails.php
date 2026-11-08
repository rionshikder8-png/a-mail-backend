<?php
declare(strict_types=1);

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . "/services/sync_service.php";
require_once dirname(__DIR__) . "/services/storage_service.php";
require_once dirname(__DIR__) . "/includes/notification_helpers.php";

echo json_encode(["woken" => sync_wake_snoozed(), "scheduled_sent" => sync_send_scheduled()]), "\n";
cron_beat("scheduled-emails");
