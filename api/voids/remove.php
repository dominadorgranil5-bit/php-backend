<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_method('POST');
require_role(['admin']);

$body = json_body();
$code = require_field($body, 'code');
$code = preg_replace('/\s+/', '', $code);

db()->prepare('DELETE FROM void_numbers WHERE code = :code')->execute(['code' => $code]);

$stmt = db()->query('SELECT code FROM void_numbers ORDER BY created_at ASC');
json_out(array_column($stmt->fetchAll(), 'code'));
