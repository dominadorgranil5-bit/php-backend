<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/address_helpers.php';
require_method('POST');

$user = require_user();
$body = json_body();
$data = address_input($body);
$wantsDefault = !empty($body['is_default']);

$pdo = db();
$pdo->beginTransaction();
try {
    lock_user_row($user['id']);

    $count = $pdo->prepare('SELECT COUNT(*) FROM customer_addresses WHERE user_id = :u');
    $count->execute(['u' => $user['id']]);
    $count = (int)$count->fetchColumn();

    if ($count >= MAX_ADDRESSES) {
        $pdo->rollBack();
        json_error('You can save up to ' . MAX_ADDRESSES . ' addresses. Delete one to add a new address.', 409);
    }

    // The very first address is always the default.
    $isDefault = ($count === 0) || $wantsDefault;
    if ($isDefault) {
        $pdo->prepare('UPDATE customer_addresses SET is_default = 0 WHERE user_id = :u')
            ->execute(['u' => $user['id']]);
    }

    $pdo->prepare(
        'INSERT INTO customer_addresses (id, user_id, label, street, barangay, city, province, postal_code, is_default)
         VALUES (:id, :u, :label, :street, :barangay, :city, :province, :postal, :def)'
    )->execute([
        'id' => make_uuid(), 'u' => $user['id'],
        'label' => $data['label'], 'street' => $data['street'],
        'barangay' => $data['barangay'], 'city' => $data['city'],
        'province' => $data['province'], 'postal' => $data['postal_code'],
        'def' => $isDefault ? 1 : 0,
    ]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}

json_out(['addresses' => addresses_for_user($user['id']), 'max' => MAX_ADDRESSES], 201);
