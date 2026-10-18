<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/address_helpers.php';
require_method('GET');

$user = require_user();
json_out(['addresses' => addresses_for_user($user['id']), 'max' => MAX_ADDRESSES]);
