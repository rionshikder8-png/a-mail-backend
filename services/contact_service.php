<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';

/** Validate contact input. Returns [name, email, phone, note, favourite]. */
function contact_input(): array
{
    $name = input_str('name', '', 120);
    $email = strtolower(input_str('email', '', 254));
    $phone = input_str('phone', '', 32);
    $note = input_str('note', '', 500);
    $fields = [];
    if ($name === '') {
        $fields['name'] = 'Enter a name.';
    }
    if (!valid_email($email)) {
        $fields['email'] = 'Enter a valid email address.';
    }
    if ($phone !== '' && !preg_match('/^\+?[0-9 ()\-]{6,24}$/', $phone)) {
        $fields['phone'] = 'Enter a valid phone number.';
    }
    if ($fields) {
        fail('validation', 'Please fix the highlighted fields.', 422, $fields);
    }
    return [$name, $email, $phone ?: null, $note ?: null, input_bool('favourite') ? 1 : 0];
}
