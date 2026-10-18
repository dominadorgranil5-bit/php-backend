<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_method('POST');
require_role(['admin']);

$id = require_field(json_body(), 'id');
$pdo = db();

$used = $pdo->prepare('SELECT COUNT(*) FROM product_recipes WHERE ingredient_id = :id');
$used->execute(['id' => $id]);
if ((int)$used->fetchColumn() > 0) {
    json_error('This ingredient is used in a recipe. Remove it from the recipes first.', 409);
}

$pdo->prepare('DELETE FROM ingredients WHERE id = :id')->execute(['id' => $id]);
json_out(['ok' => true]);
