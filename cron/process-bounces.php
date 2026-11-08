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
// Re-open sends that the relay never confirmed: rows stuck in "sending" for >15 minutes go back to "deferred".
$n = q("UPDATE mail_queue SET status = \"deferred\", next_attempt_at = UTC_TIMESTAMP() WHERE status = \"sending\" AND created_at < (UTC_TIMESTAMP() - INTERVAL 15 MINUTE) AND next_attempt_at < (UTC_TIMESTAMP() - INTERVAL 15 MINUTE)")->rowCount();
echo json_encode(["requeued_stuck" => $n]), "\n";
cron_beat("process-bounces");
