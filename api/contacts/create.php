<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/services/contact_service.php';

require_method('POST');
$u = auth_user();
[$name, $email, $phone, $note, $fav] = contact_input();
if ((int)q_val('SELECT COUNT(*) FROM contacts WHERE user_id = ?', [$u['id']]) >= 5000) {
    fail('limit_reached', 'You have reached the contact limit.', 422);
}
try {
    q('INSERT INTO contacts (user_id, name, email, phone, note, favourite) VALUES (?,?,?,?,?,?)', [$u['id'], $name, $email, $phone, $note, $fav]);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        fail('validation', 'You already have a contact with that email.', 409, ['email' => 'duplicate']);
    }
    throw $e;
}
json_out(['success' => true, 'data' => ['id' => (string)db()->lastInsertId(), 'name' => $name, 'email' => $email, 'phone' => $phone, 'note' => $note, 'favourite' => (bool)$fav], 'error' => null], 201);
