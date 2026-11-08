<?php
declare(strict_types=1);

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . "/services/sync_service.php";
require_once dirname(__DIR__) . "/services/storage_service.php";
require_once dirname(__DIR__) . "/includes/notification_helpers.php";

echo json_encode(["purged" => sync_purge_old(), "orphans" => sync_clean_orphan_uploads(), "broadcasts" => sync_broadcasts()]), "\n";
