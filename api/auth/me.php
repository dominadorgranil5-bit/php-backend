<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_method('GET');

$user = current_user();
json_out(['user' => $user]); // null if not signed in / expired token — frontend treats that as logged out
