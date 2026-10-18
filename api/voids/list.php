<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_method('GET');
require_role(['admin']);

$stmt = db()->query('SELECT code FROM void_numbers ORDER BY created_at ASC');
$codes = array_column($stmt->fetchAll(), 'code');

json_out($codes);
