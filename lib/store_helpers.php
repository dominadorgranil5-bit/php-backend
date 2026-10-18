<?php
/** lib/store_helpers.php — store info (hours, contact, address, map pin) shown in the landing page's About panel. */

function store_info_defaults(): array
{
    return [
        'open_days' => 'Monday-Sunday',
        'open_time' => '11:00',
        'close_time' => '20:00',
        'contact_number' => '',
        'email' => '',
        'address' => '',
        'maps_url' => '',
    ];
}

/** The single settings row; falls back to defaults if it hasn't been created yet. */
function store_info_get(): array
{
    $row = db()->query(
        "SELECT open_days,
                TIME_FORMAT(open_time, '%H:%i')  AS open_time,
                TIME_FORMAT(close_time, '%H:%i') AS close_time,
                contact_number, email, address, maps_url
         FROM store_settings WHERE id = 1"
    )->fetch();

    if (!$row) return store_info_defaults();
    return array_map(fn($v) => (string)$v, $row);
}

/** Only Google Maps links are accepted, and only over https. */
function store_maps_url_ok(string $url): bool
{
    $p = parse_url($url);
    if (!$p || strtolower((string)($p['scheme'] ?? '')) !== 'https') return false;
    $host = strtolower((string)($p['host'] ?? ''));
    $path = (string)($p['path'] ?? '');

    if ($host === 'maps.app.goo.gl' || $host === 'goo.gl') return true;
    if (preg_match('/(^|\.)google\.[a-z]{2,3}(\.[a-z]{2})?$/', $host)) {
        return str_starts_with($host, 'maps.') || str_starts_with($path, '/maps');
    }
    return false;
}

/** Trims + validates the Store Information form body. Sends a 400 on any problem. */
function store_info_input(array $body): array
{
    $get = fn(string $k) => trim((string)($body[$k] ?? ''));
    $d = [
        'open_days' => $get('open_days'),
        'open_time' => $get('open_time'),
        'close_time' => $get('close_time'),
        'contact_number' => $get('contact_number'),
        'email' => $get('email'),
        'address' => $get('address'),
        'maps_url' => $get('maps_url'),
    ];

    if ($d['open_days'] === '') json_error('Days open is required (e.g. Monday-Sunday).', 400);

    $timeRe = '/^([01]\d|2[0-3]):[0-5]\d$/';
    if (!preg_match($timeRe, $d['open_time']))  json_error('Please enter a valid opening time.', 400);
    if (!preg_match($timeRe, $d['close_time'])) json_error('Please enter a valid closing time.', 400);
    if ($d['open_time'] === $d['close_time'])   json_error('Opening and closing time cannot be the same.', 400);

    if ($d['contact_number'] !== '') {
        $digits = preg_replace('/\D/', '', $d['contact_number']);
        if (!preg_match('/^[0-9+\-() ]+$/', $d['contact_number']) || strlen($digits) < 7 || strlen($digits) > 15) {
            json_error('Contact number must have 7 to 15 digits (numbers, spaces, + - ( ) only).', 400);
        }
    }
    if ($d['email'] !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
        json_error('Please enter a valid email address.', 400);
    }
    if ($d['maps_url'] !== '' && !store_maps_url_ok($d['maps_url'])) {
        json_error('Please paste a valid Google Maps link (https://maps.app.goo.gl/... or https://www.google.com/maps/...).', 400);
    }

    foreach (['open_days' => 100, 'contact_number' => 30, 'email' => 150, 'address' => 255, 'maps_url' => 500] as $key => $max) {
        if (mb_strlen($d[$key]) > $max) json_error(ucfirst(str_replace('_', ' ', $key)) . " is too long (max $max characters).", 400);
    }
    return $d;
}
