<?php
/** GET /api/cart/get.php — the signed-in account's saved cart (same on every device). */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/cart_helpers.php';
require_method('GET');

$user = require_user();
json_out(['items' => cart_items_for_user($user['id']), 'max' => MAX_CART_ITEMS]);
