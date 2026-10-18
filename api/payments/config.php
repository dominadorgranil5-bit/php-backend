<?php
// api/payments/config.php — tells the app whether GCash is running in PayMongo TEST or LIVE mode
// (so it can show a "TEST MODE" note). Never returns the key itself.
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/paymongo.php';
require_method('GET');

json_out(['mode' => paymongo_key_mode(), 'test_mode' => paymongo_key_mode() === 'test']);
