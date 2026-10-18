<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/menu_helpers.php';
require_once __DIR__ . '/../../lib/inventory_helpers.php';
require_method('GET');

$stmt = db()->query(
    'SELECT mi.*
     FROM menu_items mi
     WHERE mi.available = 1
     ORDER BY mi.category ASC'
);
json_out(attach_out_of_stock(attach_menu_options($stmt->fetchAll())));
