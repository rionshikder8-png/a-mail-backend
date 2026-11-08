<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/services/contact_service.php';

require_method('POST');
$u = auth_user();
$id = input_int('id', 0);
if (!q_val('SELECT 1 FROM contacts WHERE id = ? AND user_id = ?', [$id, $u['id']])) {
    fail('not_found', 'Contact not found.', 404);
}
[$name, $email, $phone, $note, $fav] = contact_input();
try {
    q('UPDATE contacts SET name = ?, email = ?, phone = ?, note = ?, favourite = ? WHERE id = ? AND user_id = ?', [$name, $email, $phone, $note, $fav, $id, $u['id']]);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        fail('validation', 'You already have a contact with that email.', 409, ['email' => 'duplicate']);
    }
    throw $e;
}
ok(['id' => (string)$id, 'name' => $name, 'email' => $email, 'phone' => $phone, 'note' => $note, 'favourite' => (bool)$fav]);
