<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_method('GET');
require_role(['admin']);

$email = strtolower(trim((string)($_GET['email'] ?? '')));
if ($email === '') {
    json_error('email query parameter is required.', 400);
}

$stmt = db()->prepare('SELECT id, name, email, phone, role FROM users WHERE email = :email');
$stmt->execute(['email' => $email]);
$user = $stmt->fetch();

json_out(['user' => $user ?: null]);
