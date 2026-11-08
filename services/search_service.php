<?php
declare(strict_types=1);

require_once __DIR__ . '/email_service.php';

/** Gmail-like search across the user's own mailbox only. All values are bound parameters. */
function email_search(int $userId): never
{
    [$page, $per, $off] = paging();
    $where = ['e.user_id = ?'];
    $args = [$userId];
    $folder = input_str('folder', 'all', 20);
    if ($folder === 'inbox' || isset(FOLDER_WHERE[$folder])) {
        $where[] = '(' . FOLDER_WHERE[$folder] . ')';
    } else {
        $where[] = '(' . FOLDER_WHERE['all'] . ')';
    }
    // Searching in trash/spam explicitly is allowed through the folder param above.
    $like = static fn(string $v): string => '%' . addcslashes($v, '%_\\') . '%';

    if (($v = input_str('q', '', 200)) !== '') {
        $where[] = '(e.subject LIKE ? OR e.body_text LIKE ? OR e.from_address LIKE ? OR e.from_name LIKE ?)';
        array_push($args, $like($v), $like($v), $like($v), $like($v));
    }
    if (($v = input_str('from', '', 200)) !== '') {
        $where[] = '(e.from_address LIKE ? OR e.from_name LIKE ?)';
        array_push($args, $like($v), $like($v));
    }
    if (($v = input_str('to', '', 200)) !== '') {
        $where[] = 'EXISTS (SELECT 1 FROM email_recipients r WHERE r.email_id = e.id AND (r.address LIKE ? OR r.name LIKE ?))';
        array_push($args, $like($v), $like($v));
    }
    if (($v = input_str('subject', '', 200)) !== '') {
        $where[] = 'e.subject LIKE ?';
        $args[] = $like($v);
    }
    if (input_int('has_attachment', 0) === 1) {
        $where[] = 'e.has_attachments = 1';
    }
    if (($v = iso_to_db(input_str('after', '', 40) ?: null)) !== null) {
        $where[] = 'e.received_at >= ?';
        $args[] = $v;
    }
    if (($v = iso_to_db(input_str('before', '', 40) ?: null)) !== null) {
        $where[] = 'e.received_at <= ?';
        $args[] = $v;
    }
    if (($v = input_int('label_id', 0)) > 0) {
        $where[] = 'EXISTS (SELECT 1 FROM email_labels el JOIN labels lb ON lb.id = el.label_id WHERE el.email_id = e.id AND el.label_id = ? AND lb.user_id = e.user_id)';
        $args[] = $v;
    }
    $sql = 'FROM emails e WHERE ' . implode(' AND ', $where);
    $total = (int)q_val("SELECT COUNT(*) $sql", $args);
    $rows = q_all("SELECT e.* $sql ORDER BY e.received_at DESC, e.id DESC LIMIT $per OFFSET $off", $args);
    paged(email_dtos($rows), $page, $per, $total);
}
