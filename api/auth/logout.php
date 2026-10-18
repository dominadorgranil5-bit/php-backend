<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_method('POST');

revoke_session();
json_out(['ok' => true]);
