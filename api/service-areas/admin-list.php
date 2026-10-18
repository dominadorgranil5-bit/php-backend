<?php
/** Everything (including disabled) for the admin's Delivery Addresses screen. */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/service_area_helpers.php';
require_method('GET');

require_role(['admin']);
json_out(['cities' => service_areas_tree(false)]);
