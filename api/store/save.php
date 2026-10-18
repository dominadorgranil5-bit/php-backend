<?php
/**
 * Admin: save the store info.
 * Body: { open_days, open_time "HH:MM", close_time "HH:MM", contact_number, email, address, maps_url }
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/store_helpers.php';
require_method('POST');
require_role(['admin']);

$d = store_info_input(json_body());

db()->prepare(
    'INSERT INTO store_settings (id, open_days, open_time, close_time, contact_number, email, address, maps_url)
     VALUES (1, :days, :open, :close, :phone, :email, :addr, :maps)
     ON DUPLICATE KEY UPDATE
       open_days = VALUES(open_days), open_time = VALUES(open_time), close_time = VALUES(close_time),
       contact_number = VALUES(contact_number), email = VALUES(email),
       address = VALUES(address), maps_url = VALUES(maps_url)'
)->execute([
    'days' => $d['open_days'], 'open' => $d['open_time'] . ':00', 'close' => $d['close_time'] . ':00',
    'phone' => $d['contact_number'], 'email' => $d['email'], 'addr' => $d['address'], 'maps' => $d['maps_url'],
]);

json_out(['store' => store_info_get()]);
