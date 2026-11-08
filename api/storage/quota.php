<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/services/storage_service.php';
require_once dirname(__DIR__, 2) . '/services/attachment_service.php';

require_method('GET');
$u = auth_user();
$data = storage_dto((int)$u['id']);
if (input_str('include') === 'attachments') {
    $rows = q_all('SELECT id, file_name, mime_type, size_bytes FROM attachments WHERE user_id = ? ORDER BY size_bytes DESC LIMIT 200', [$u['id']]);
    $data['attachments'] = array_map('attachment_dto', $rows);
}
ok($data);
