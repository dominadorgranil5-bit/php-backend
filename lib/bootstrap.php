<?php
/** lib/bootstrap.php — required at the top of every api/**.php entry point. */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0'); // never leak PHP errors as HTML into a JSON API

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/cors.php';

apply_cors();

// Convert any uncaught error/exception into a clean JSON 500 instead of an HTML dump.
set_exception_handler(function (Throwable $e) {
    error_log('[unhandled] ' . $e->getMessage());
    // With APP_DEBUG=true in .env the real reason is shown, so the app's error message says what failed.
    json_error('Server error' . (config('APP_DEBUG', 'false') === 'true' ? ' (' . $e->getMessage() . ')' : ''), 500);
});
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});