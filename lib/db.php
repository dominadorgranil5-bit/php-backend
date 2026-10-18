<?php
/** lib/db.php — single shared PDO connection (MySQL). */

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $host = config('DB_HOST', '127.0.0.1');
    $port = config('DB_PORT', '3306');
    $name = config('DB_NAME', 'drewscafe');
    $user = config('DB_USER', 'root');
    $pass = config('DB_PASS', '');

    $dsn = "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4";

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

/** Generates a UUID v4 string (used for all primary keys, mirrors Supabase's uuid columns). */
function make_uuid(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}
