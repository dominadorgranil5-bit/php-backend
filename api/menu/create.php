<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/menu_helpers.php';
require_method('POST');
require_role(['staff', 'admin']);

$body = json_body();
$name = require_field($body, 'name');
$price = (float)($body['price'] ?? 0);
$category = (string)($body['category'] ?? '');

$id = make_uuid();
$stmt = db()->prepare(
    'INSERT INTO menu_items (id, name, description, price, category, emoji, image_url, available)
     VALUES (:id, :name, :description, :price, :category, :emoji, :image_url, :available)'
);
$stmt->execute([
    'id' => $id,
    'name' => $name,
    'description' => (string)($body['description'] ?? ''),
    'price' => $price,
    'category' => $category,
    'emoji' => (string)($body['emoji'] ?? ''),
    'image_url' => $body['image_url'] ?? null,
    'available' => !empty($body['available']) ? 1 : 0,
]);

save_menu_options(
    $id,
    is_no_option_category($category) ? [] : (isset($body['sizes']) && is_array($body['sizes']) ? $body['sizes'] : (is_drink_category($category) ? default_drink_sizes($price) : null)),
    is_no_option_category($category) ? [] : (isset($body['add_ons']) && is_array($body['add_ons']) ? $body['add_ons'] : (is_drink_category($category) ? default_drink_add_ons() : null))
);

json_out(['id' => $id], 201);
