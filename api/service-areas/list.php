<?php
/** Active Municipality + Barangays — what the customer's address form shows. Any signed-in user. */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/service_area_helpers.php';
require_method('GET');

require_user();
$cities = array_values(array_filter(
    service_areas_tree(true),
    fn($c) => count($c['barangays']) > 0   // a Municipality with no barangay yet isn't selectable
));
json_out(['cities' => $cities]);
