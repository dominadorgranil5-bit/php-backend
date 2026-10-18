<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/address_helpers.php';
require_method('POST');

$user = require_user();
$body = json_body();
$id = require_field($body, 'id');
$wantsDefault = !empty($body['is_default']);

$existing = address_row_for_user($id, $user['id']);
if (!$existing) {
    json_error('Address not found.', 404);
}
$data = address_input($body, $existing);

$pdo = db();
$pdo->beginTransaction();
try {
    lock_user_row($user['id']);

    if ($wantsDefault) {
        $pdo->prepare('UPDATE customer_addresses SET is_default = 0 WHERE user_id = :u')
            ->execute(['u' => $user['id']]);
    }

    $sql = 'UPDATE customer_addresses
            SET label = :label, street = :street, barangay = :barangay, city = :city,
                province = :province, postal_code = :postal'
         . ($wantsDefault ? ', is_default = 1' : '')
         . ' WHERE id = :id AND user_id = :u';

    $pdo->prepare($sql)->execute([
        'label' => $data['label'], 'street' => $data['street'],
        'barangay' => $data['barangay'], 'city' => $data['city'],
        'province' => $data['province'], 'postal' => $data['postal_code'],
        'id' => $id, 'u' => $user['id'],
    ]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}

json_out(['addresses' => addresses_for_user($user['id']), 'max' => MAX_ADDRESSES]);
