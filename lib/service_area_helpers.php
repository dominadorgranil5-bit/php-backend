<?php
/** lib/service_area_helpers.php — Municipality/Barangay list the admin sets; customers pick from it. */

/**
 * Municipality list with their barangays, A–Z.
 * $onlyActive = true  -> what customers see (inactive municipality/barangay hidden)
 * $onlyActive = false -> everything (admin screen)
 */
function service_areas_tree(bool $onlyActive): array
{
    $pdo = db();

    $cities = $pdo->query(
        'SELECT id, name, province, postal_code, is_active FROM service_cities'
        . ($onlyActive ? ' WHERE is_active = 1' : '') . ' ORDER BY name ASC'
    )->fetchAll();

    $brgys = $pdo->query(
        'SELECT id, city_id, name, is_active FROM service_barangays'
        . ($onlyActive ? ' WHERE is_active = 1' : '') . ' ORDER BY name ASC'
    )->fetchAll();

    $byCity = [];
    foreach ($brgys as $b) {
        $byCity[$b['city_id']][] = [
            'id' => $b['id'], 'name' => $b['name'], 'is_active' => (bool)(int)$b['is_active'],
        ];
    }

    return array_map(function ($c) use ($byCity) {
        return [
            'id' => $c['id'], 'name' => $c['name'], 'province' => $c['province'],
            'postal_code' => $c['postal_code'], 'is_active' => (bool)(int)$c['is_active'],
            'barangays' => $byCity[$c['id']] ?? [],
        ];
    }, $cities);
}

/** Trims + validates a Municipality form body. Sends a 400 on any problem. */
function service_city_input(array $body): array
{
    $get = fn(string $k) => trim((string)($body[$k] ?? ''));
    $data = ['name' => $get('name'), 'province' => $get('province'), 'postal_code' => $get('postal_code')];

    if ($data['name'] === '')        json_error('Municipality name is required.', 400);
    if ($data['province'] === '')    json_error('Province is required.', 400);
    if ($data['postal_code'] === '') json_error('Postal code is required.', 400);
    if (!preg_match('/^\d{4}$/', $data['postal_code'])) json_error('Postal code must be 4 digits.', 400);
    foreach (['name' => 100, 'province' => 100] as $key => $max) {
        if (mb_strlen($data[$key]) > $max) json_error(ucfirst($key) . " is too long (max $max characters).", 400);
    }
    return $data;
}

/**
 * Checks that a customer's chosen Municipality + Barangay is one the admin allows (both active).
 * Returns the admin's canonical values — including the province and postal code the admin
 * set for that Municipality — so the customer can't send their own. Sends a 400 otherwise.
 *
 * @return array{city:string, barangay:string, province:string, postal_code:string}
 */
function resolve_service_area(string $city, string $barangay): array
{
    $stmt = db()->prepare(
        'SELECT c.name AS city, b.name AS barangay, c.province, c.postal_code
         FROM service_cities c
         JOIN service_barangays b ON b.city_id = c.id
         WHERE c.name = :c AND b.name = :b AND c.is_active = 1 AND b.is_active = 1
         LIMIT 1'
    );
    $stmt->execute(['c' => $city, 'b' => $barangay]);
    $row = $stmt->fetch();
    if (!$row) {
        json_error('Please choose a Municipality and Barangay from the list. We only deliver to the areas listed.', 400);
    }
    return $row;
}
