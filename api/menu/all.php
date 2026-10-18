<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/menu_helpers.php';
require_once __DIR__ . '/../../lib/inventory_helpers.php';
require_method('GET');

$stmt = db()->query(
    'SELECT mi.*
     FROM menu_items mi
     ORDER BY mi.created_at DESC'
);
// attach_stock_yield adds max_qty / limiting_ingredient / stock_status for the Admin > Menu screen.
json_out(attach_stock_yield(attach_out_of_stock(attach_menu_options($stmt->fetchAll()))));
