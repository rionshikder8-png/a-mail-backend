<?php
declare(strict_types=1);

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . "/services/sync_service.php";
require_once dirname(__DIR__) . "/services/storage_service.php";
require_once dirname(__DIR__) . "/includes/notification_helpers.php";

$n = 0;
foreach (q_all("SELECT id FROM users WHERE status = \"active\"") as $u) {
    $d = storage_dto((int)$u["id"]);
    if ($d["quota_bytes"] > 0 && $d["used_bytes"] / $d["quota_bytes"] >= 0.9) {
        $recent = q_val("SELECT 1 FROM notifications WHERE user_id = ? AND title = ? AND created_at > (UTC_TIMESTAMP() - INTERVAL 7 DAY)", [$u["id"], "Storage almost full"]);
        if (!$recent) {
            notify_user((int)$u["id"], "system", "Storage almost full", "You have used " . round($d["used_bytes"] / $d["quota_bytes"] * 100) . "% of your storage.");
        }
    }
    $n++;
}
echo json_encode(["users_checked" => $n]), "\n";
