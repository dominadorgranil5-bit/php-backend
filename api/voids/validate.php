<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_method('POST');
require_role(['staff', 'admin']);

$body = json_body();
$code = require_field($body, 'code');
$code = preg_replace('/\s+/', '', $code);

$stmt = db()->prepare('SELECT id FROM void_numbers WHERE code = :code');
$stmt->execute(['code' => $code]);

json_out(['valid' => (bool)$stmt->fetch()]);
