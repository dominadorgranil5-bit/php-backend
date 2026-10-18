<?php
/**
 * Admin: add or edit Barangays of a Municipality.
 *  - Add (one or many):  { city_id, names: ["Poblacion", "San Jose"] }  (or { city_id, name })
 *  - Edit / enable / disable: { id, name?, is_active? }
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/service_area_helpers.php';
require_method('POST');
require_role(['admin']);

$body = json_body();
$pdo = db();
$id = trim((string)($body['id'] ?? ''));

if ($id !== '') {
    $stmt = $pdo->prepare('SELECT id, city_id, name FROM service_barangays WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    if (!$row) json_error('Barangay not found.', 404);

    $name = array_key_exists('name', $body) ? trim((string)$body['name']) : $row['name'];
    if ($name === '') json_error('Barangay name is required.', 400);
    if (mb_strlen($name) > 100) json_error('Barangay name is too long (max 100 characters).', 400);

    $dup = $pdo->prepare('SELECT 1 FROM service_barangays WHERE city_id = :c AND name = :n AND id <> :id');
    $dup->execute(['c' => $row['city_id'], 'n' => $name, 'id' => $id]);
    if ($dup->fetchColumn()) json_error('That Barangay is already in this Municipality.', 409);

    $params = ['n' => $name, 'id' => $id];
    $setActive = '';
    if (array_key_exists('is_active', $body)) {
        $setActive = ', is_active = :a';
        $params['a'] = !empty($body['is_active']) ? 1 : 0;
    }
    $pdo->prepare("UPDATE service_barangays SET name = :n$setActive WHERE id = :id")->execute($params);
    json_out(['cities' => service_areas_tree(false)]);
}

// Add
$cityId = require_field($body, 'city_id');
$check = $pdo->prepare('SELECT 1 FROM service_cities WHERE id = :id');
$check->execute(['id' => $cityId]);
if (!$check->fetchColumn()) json_error('Municipality not found.', 404);

$names = isset($body['names']) && is_array($body['names']) ? $body['names'] : [(string)($body['name'] ?? '')];
$clean = [];
foreach ($names as $n) {
    $n = trim(preg_replace('/\s+/', ' ', (string)$n));
    if ($n === '') continue;
    if (mb_strlen($n) > 100) json_error("Barangay name is too long (max 100 characters): $n", 400);
    $clean[mb_strtolower($n)] = $n;           // de-duplicate within the request
}
if (!$clean) json_error('Enter at least one Barangay name.', 400);
if (count($clean) > 100) json_error('You can add up to 100 barangays at a time.', 400);

$exists = $pdo->prepare('SELECT 1 FROM service_barangays WHERE city_id = :c AND name = :n');
$insert = $pdo->prepare('INSERT INTO service_barangays (id, city_id, name, is_active) VALUES (:id, :c, :n, 1)');
$added = 0;
$pdo->beginTransaction();
try {
    foreach ($clean as $n) {
        $exists->execute(['c' => $cityId, 'n' => $n]);
        if ($exists->fetchColumn()) continue;   // already there (case-insensitive via collation) — skip
        $insert->execute(['id' => make_uuid(), 'c' => $cityId, 'n' => $n]);
        $added++;
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}

if ($added === 0) json_error('Those Barangays are already in this Municipality.', 409);
json_out(['cities' => service_areas_tree(false), 'added' => $added], 201);
