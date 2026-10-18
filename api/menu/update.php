<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/menu_helpers.php';
require_method('POST');
require_role(['staff', 'admin']);

$body = json_body();
$id = require_field($body, 'id');

// Only update fields that were actually sent (mirrors Supabase's partial update()).
$fields = [];
$params = ['id' => $id];
foreach (['name', 'description', 'category', 'emoji', 'image_url'] as $key) {
    if (array_key_exists($key, $body)) {
        $fields[] = "$key = :$key";
        $params[$key] = $body[$key];
    }
}
if (array_key_exists('price', $body)) {
    $fields[] = 'price = :price';
    $params['price'] = (float)$body['price'];
}
if (array_key_exists('available', $body)) {
    $fields[] = 'available = :available';
    $params['available'] = !empty($body['available']) ? 1 : 0;
}

if (empty($fields)) {
    json_error('No fields to update.', 400);
}

$sql = 'UPDATE menu_items SET ' . implode(', ', $fields) . ' WHERE id = :id';
 $pdo = db();
 $pdo->beginTransaction();
 try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    if ($stmt->rowCount() === 0) {
        $exists = $pdo->prepare('SELECT 1 FROM menu_items WHERE id = :id');
        $exists->execute(['id' => $id]);
        if (!$exists->fetchColumn()) {
            $pdo->rollBack();
            json_error('Menu item not found.', 404);
        }
    }

    save_menu_options(
        $id,
        isset($body['category']) && is_no_option_category((string)$body['category']) ? [] : (isset($body['sizes']) && is_array($body['sizes']) ? $body['sizes'] : null),
        isset($body['category']) && is_no_option_category((string)$body['category']) ? [] : (isset($body['add_ons']) && is_array($body['add_ons']) ? $body['add_ons'] : null)
    );
    $pdo->commit();
 } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
 }

json_out(['ok' => true]);
