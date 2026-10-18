<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_method('POST');
$user = require_role(['admin']);

$body = json_body();
$code = require_field($body, 'code');
$code = preg_replace('/\s+/', '', $code);

if ($code === '') {
    json_error('Void number cannot be empty.', 400);
}

$pdo = db();

$stmt = $pdo->prepare('SELECT id FROM void_numbers WHERE code = :code');
$stmt->execute(['code' => $code]);
if (!$stmt->fetch()) {
    $stmt = $pdo->prepare(
        'INSERT INTO void_numbers (id, code, created_by) VALUES (:id, :code, :created_by)'
    );
    $stmt->execute(['id' => make_uuid(), 'code' => $code, 'created_by' => $user['id']]);
}

$stmt = $pdo->query('SELECT code FROM void_numbers ORDER BY created_at ASC');
json_out(array_column($stmt->fetchAll(), 'code'));
