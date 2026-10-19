<?php
/**
 * GET /api/menu/image.php?f=<file name>   (public — used by <img> tags)
 *
 * Serves a menu photo from uploads/menu-images; if the file is missing (folder lost, new server,
 * restored database) it is read from the menu_images table and the file is re-created.
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/menu_images.php';
require_method('GET');

$name = basename((string)($_GET['f'] ?? ''));
if (!preg_match('~^' . MENU_IMAGE_NAME_PATTERN . '$~i', $name)) {
    http_response_code(404);
    exit;
}

$path = menu_images_dir() . '/' . $name;
$mime = menu_image_mime_for($name);
$bytes = null;

if (is_file($path)) {
    $bytes = file_get_contents($path);
} else {
    try {
        menu_images_ensure_table();
        $stmt = db()->prepare('SELECT mime, data FROM menu_images WHERE filename = :f');
        $stmt->execute(['f' => $name]);
        $row = $stmt->fetch();
        if ($row) {
            $bytes = $row['data'];
            $mime = $row['mime'] ?: $mime;
            // Put the file back so the next request is served straight from disk.
            if (!is_dir(menu_images_dir())) {
                @mkdir(menu_images_dir(), 0755, true);
            }
            @file_put_contents($path, $bytes);
        }
    } catch (Throwable $e) {
        error_log('[menu image] ' . $e->getMessage());
    }
}

if ($bytes === null || $bytes === false) {
    http_response_code(404);
    exit;
}

header_remove('Content-Type');
header('Content-Type: ' . $mime);
header('Content-Length: ' . strlen($bytes));
header('Cache-Control: public, max-age=31536000, immutable'); // file names are unique per upload
header('X-Content-Type-Options: nosniff');
echo $bytes;
