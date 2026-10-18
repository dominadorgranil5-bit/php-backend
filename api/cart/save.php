<?php
/** POST /api/cart/save.php  { items: [...] } — replaces the signed-in account's saved cart. */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/cart_helpers.php';
require_method('POST');

$user = require_user();
$body = json_body();
$items = sanitize_cart_items($body['items'] ?? []);
save_cart_items($user['id'], $items);

json_out(['ok' => true]);
