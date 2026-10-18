<?php
/** Store info for the landing page's About panel. PUBLIC — visitors aren't signed in. */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/store_helpers.php';
require_method('GET');

json_out(['store' => store_info_get()]);
