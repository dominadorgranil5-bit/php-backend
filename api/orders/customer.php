<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/orders_helpers.php';
require_method('GET');

$user = require_user();

$stmt = db()->prepare('SELECT * FROM orders WHERE customer_id = :id ORDER BY created_at DESC');
$stmt->execute(['id' => $user['id']]);
$orders = $stmt->fetchAll();

json_out(attach_order_items($orders));
