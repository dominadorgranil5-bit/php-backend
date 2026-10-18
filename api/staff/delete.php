<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_method('POST');
$admin = require_role(['admin']);

$body = json_body();
$id = require_field($body, 'id');

if ($id === $admin['id']) {
    json_error('You cannot remove your own account.', 400);
}

$pdo = db();

$stmt = $pdo->prepare('SELECT id, role FROM users WHERE id = :id');
$stmt->execute(['id' => $id]);
$target = $stmt->fetch();
if (!$target) {
    json_error('Account not found.', 404);
}

if (!in_array($target['role'], ['staff', 'rider'], true)) {
    json_error('Only staff or rider accounts can be removed this way.', 400);
}

try {
    $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $id]);
} catch (PDOException $e) {
    // 23000 = integrity constraint violation — this staff member has records
    // (e.g. recorded expenses) that are intentionally protected from deletion
    // for audit purposes. Disable the account instead in that case.
    if ($e->getCode() === '23000') {
        json_error('This account has recorded expenses tied to it and can\'t be deleted. Use Disable instead to block their access.', 409);
    }
    throw $e;
}

json_out(['ok' => true]);
