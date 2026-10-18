<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/cod_helpers.php';
require_method('GET');

$user = require_user();
json_out(cod_eligibility(db(), $user['id']));
