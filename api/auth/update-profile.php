<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_method('POST'); // sent as POST for simplicity (works from plain fetch/forms too)

$user = require_user();
$body = json_body();
$name = require_field($body, 'name');
$phone = trim((string)($body['phone'] ?? ''));

$stmt = db()->prepare('UPDATE users SET name = :name, phone = :phone WHERE id = :id');
$stmt->execute(['name' => $name, 'phone' => $phone, 'id' => $user['id']]);

json_out(['ok' => true]);
