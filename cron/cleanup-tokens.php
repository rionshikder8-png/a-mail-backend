<?php
declare(strict_types=1);

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . "/services/sync_service.php";
require_once dirname(__DIR__) . "/services/storage_service.php";
require_once dirname(__DIR__) . "/includes/notification_helpers.php";

$a = q("DELETE FROM refresh_tokens WHERE expires_at < (UTC_TIMESTAMP() - INTERVAL 1 DAY)")->rowCount();
$b = q("DELETE FROM sessions WHERE expires_at < (UTC_TIMESTAMP() - INTERVAL 1 DAY)")->rowCount();
$c = q("DELETE FROM otp_codes WHERE expires_at < (UTC_TIMESTAMP() - INTERVAL 1 DAY)")->rowCount();
$d = q("DELETE FROM rate_limits WHERE window_start < UNIX_TIMESTAMP() - 86400")->rowCount();
$e = q("DELETE FROM login_history WHERE created_at < (UTC_TIMESTAMP() - INTERVAL 180 DAY)")->rowCount();
echo json_encode(["refresh_tokens" => $a, "sessions" => $b, "otp" => $c, "rate_limits" => $d, "login_history" => $e]), "\n";
