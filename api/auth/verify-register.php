<?php
/** Step 2 of signup: checks the emailed code, creates the customer account, signs them in. */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/otp.php';
require_method('POST');

$body = json_body();
$email = strtolower(trim(require_field($body, 'email')));
$code = preg_replace('/\D/', '', require_field($body, 'code'));

$row = otp_verify($email, 'register', $code);
if (!$row['pending_password_hash'] || !$row['pending_name']) {
    json_error('Signup details were lost. Please sign up again.', 400);
}

$pdo = db();
$stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email');
$stmt->execute(['email' => $email]);
if ($stmt->fetch()) {
    json_error('An account with that email already exists.', 409);
}

otp_consume($row['id']);

$id = make_uuid();
$name = $row['pending_name'];
$phone = (string)($row['pending_phone'] ?? '');

try {
    $stmt = $pdo->prepare(
        'INSERT INTO users (id, name, email, phone, password_hash, role)
         VALUES (:id, :name, :email, :phone, :hash, "customer")'
    );
    $stmt->execute([
        'id' => $id, 'name' => $name, 'email' => $email,
        'phone' => $phone, 'hash' => $row['pending_password_hash'],
    ]);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        json_error('An account with that email already exists.', 409);
    }
    throw $e;
}

$token = issue_session($id);

json_out([
    'token' => $token,
    'user' => ['id' => $id, 'name' => $name, 'email' => $email, 'phone' => $phone, 'role' => 'customer'],
], 201);
