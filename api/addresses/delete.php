<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/address_helpers.php';
require_method('POST');

$user = require_user();
$id = require_field(json_body(), 'id');

if (!address_owned_by($id, $user['id'])) {
    json_error('Address not found.', 404);
}

$pdo = db();
$pdo->beginTransaction();
try {
    lock_user_row($user['id']);

    $wasDefault = $pdo->prepare('SELECT is_default FROM customer_addresses WHERE id = :id AND user_id = :u');
    $wasDefault->execute(['id' => $id, 'u' => $user['id']]);
    $wasDefault = (int)$wasDefault->fetchColumn() === 1;

    $pdo->prepare('DELETE FROM customer_addresses WHERE id = :id AND user_id = :u')
        ->execute(['id' => $id, 'u' => $user['id']]);

    // Deleted the default? Promote the oldest remaining address so there's always one.
    if ($wasDefault) {
        $pdo->prepare(
            'UPDATE customer_addresses SET is_default = 1
             WHERE user_id = :u ORDER BY created_at ASC, id ASC LIMIT 1'
        )->execute(['u' => $user['id']]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}

json_out(['addresses' => addresses_for_user($user['id']), 'max' => MAX_ADDRESSES]);
