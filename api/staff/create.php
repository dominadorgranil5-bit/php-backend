<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/password_validation.php';
require_once __DIR__ . '/../../lib/input_validation.php';
require_method('POST');
require_role(['admin']);

$body = json_body();
$firstName = trim((string)($body['first_name'] ?? ''));
$lastName = trim((string)($body['last_name'] ?? ''));
$legacyName = trim((string)($body['name'] ?? '')); // older clients send one full-name field
$email = strtolower(trim(require_field($body, 'email')));
$phone = trim((string)($body['phone'] ?? ''));
$password = require_field($body, 'password');
$role = require_field($body, 'role');

// Name: first + last, each letters-only and saved in Title Case. Mobile: exactly 11 digits.
if ($firstName !== '' || $lastName !== '') {
    $name = require_valid_name_part($firstName, 'First name') . ' ' . require_valid_name_part($lastName, 'Last name');
} else {
    $name = require_valid_name_part($legacyName, 'Full name');
}
$phone = require_valid_mobile($phone);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_error('Invalid email address.', 400);
}
require_valid_password($password);
if (!in_array($role, ['staff', 'admin', 'rider'], true)) {
    json_error('Role must be "staff", "admin" or "rider".', 400);
}

$pdo = db();

$stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email');
$stmt->execute(['email' => $email]);
if ($stmt->fetch()) {
    json_error('An account with that email already exists.', 409);
}

$id = make_uuid();
$hash = password_hash($password, PASSWORD_BCRYPT);

// Admin sets the role directly at creation time — unlike auth/register.php,
// which always starts new accounts as 'customer'. No session token is issued
// here: this is the admin acting on someone else's behalf, not that person
// signing themselves in.
//
// The password the admin types is only a TEMPORARY one for staff and riders: must_change_password = 1
// makes them pick their own on first sign-in (see api/auth/change-password.php).
$mustChange = in_array($role, ['staff', 'rider'], true) ? 1 : 0;
$stmt = $pdo->prepare(
    'INSERT INTO users (id, name, email, phone, password_hash, role, must_change_password)
     VALUES (:id, :name, :email, :phone, :hash, :role, :mcp)'
);
$stmt->execute(['id' => $id, 'name' => $name, 'email' => $email, 'phone' => $phone, 'hash' => $hash, 'role' => $role, 'mcp' => $mustChange]);

json_out([
    'user' => ['id' => $id, 'name' => $name, 'email' => $email, 'phone' => $phone, 'role' => $role, 'must_change_password' => (bool)$mustChange],
], 201);
