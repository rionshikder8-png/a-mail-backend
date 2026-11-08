<?php
declare(strict_types=1);

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . "/services/sync_service.php";
require_once dirname(__DIR__) . "/services/storage_service.php";
require_once dirname(__DIR__) . "/includes/notification_helpers.php";

require_once dirname(__DIR__) . "/mail/delivery.php";
$r = process_outbound_queue(100);
$r["pushed"] = push_pending();
echo json_encode($r), "\n";
cron_beat("process-outgoing");
