<?php
/**
 * Customer: send feedback to the shop.
 * Body: { message: string (required, max 1000), rating?: 1-5 }
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_method('POST');

$user = require_user();
$body = json_body();

$message = trim((string)($body['message'] ?? ''));
if ($message === '') {
    json_error('Please write your feedback.', 400);
}
if (mb_strlen($message) > 1000) {
    json_error('Feedback is too long (max 1000 characters).', 400);
}

$rating = null;
if (isset($body['rating']) && $body['rating'] !== '' && $body['rating'] !== null) {
    $rating = (int)$body['rating'];
    if ($rating < 1 || $rating > 5) {
        json_error('Rating must be from 1 to 5.', 400);
    }
}

db()->prepare(
    'INSERT INTO customer_feedback (user_id, customer_name, customer_email, rating, message)
     VALUES (:uid, :name, :email, :rating, :msg)'
)->execute([
    'uid' => (string)$user['id'],
    'name' => (string)($user['name'] ?? ''),
    'email' => (string)($user['email'] ?? ''),
    'rating' => $rating,
    'msg' => $message,
]);

json_out(['ok' => true]);
