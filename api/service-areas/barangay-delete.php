<?php
/** Admin: remove a Barangay. Customers' already-saved addresses are not touched. */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/service_area_helpers.php';
require_method('POST');
require_role(['admin']);

$id = require_field(json_body(), 'id');
$stmt = db()->prepare('DELETE FROM service_barangays WHERE id = :id');
$stmt->execute(['id' => $id]);
if ($stmt->rowCount() === 0) json_error('Barangay not found.', 404);

json_out(['cities' => service_areas_tree(false)]);
