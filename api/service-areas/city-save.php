<?php
/**
 * Admin: add or edit a Municipality.  Body: { id?, name, province, postal_code, is_active? }
 * Without id -> created. With id -> updated (existing customer addresses keep their saved text).
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/service_area_helpers.php';
require_method('POST');
require_role(['admin']);

$body = json_body();
$data = service_city_input($body);
$id = trim((string)($body['id'] ?? ''));
$active = array_key_exists('is_active', $body) ? (!empty($body['is_active']) ? 1 : 0) : 1;
$pdo = db();

$dup = $pdo->prepare('SELECT id FROM service_cities WHERE name = :n AND id <> :id');
$dup->execute(['n' => $data['name'], 'id' => $id]);
if ($dup->fetchColumn()) {
    json_error('That Municipality is already in the list.', 409);
}

if ($id !== '') {
    $exists = $pdo->prepare('SELECT 1 FROM service_cities WHERE id = :id');
    $exists->execute(['id' => $id]);
    if (!$exists->fetchColumn()) json_error('Municipality not found.', 404);

    $setActive = array_key_exists('is_active', $body) ? ', is_active = :a' : '';
    $params = ['n' => $data['name'], 'p' => $data['province'], 'z' => $data['postal_code'], 'id' => $id];
    if ($setActive) $params['a'] = $active;
    $pdo->prepare("UPDATE service_cities SET name = :n, province = :p, postal_code = :z$setActive WHERE id = :id")
        ->execute($params);
} else {
    $pdo->prepare(
        'INSERT INTO service_cities (id, name, province, postal_code, is_active) VALUES (:id, :n, :p, :z, :a)'
    )->execute(['id' => make_uuid(), 'n' => $data['name'], 'p' => $data['province'], 'z' => $data['postal_code'], 'a' => $active]);
}

json_out(['cities' => service_areas_tree(false)], $id !== '' ? 200 : 201);
