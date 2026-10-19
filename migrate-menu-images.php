<?php
/**
 * One-time (safe to run again) migration for menu photos.
 *
 *  1. Creates the menu_images table (photos are also kept INSIDE the database).
 *  2. Copies every file found in uploads/menu-images into that table.
 *  3. Rewrites menu_items.image_url from full URLs (with an old LAN IP) to just the file name.
 *  4. Lists items whose photo file exists neither on disk nor in the database.
 *
 * Run it on the machine that has the photos (your XAMPP):
 *    http://localhost/php-backend/migrate-menu-images.php
 * Then export the database and import it on GoogieHost, and the photos come with it.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/menu_images.php';

header('Content-Type: text/plain; charset=utf-8');

$pdo = db();
menu_images_ensure_table();
echo "menu_images table ready.\n";

// 2. files on disk -> database
$dir = menu_images_dir();
$copied = 0;
$onDisk = [];
if (is_dir($dir)) {
    foreach (scandir($dir) ?: [] as $file) {
        if (!preg_match('~^' . MENU_IMAGE_NAME_PATTERN . '$~i', $file)) {
            continue;
        }
        $onDisk[$file] = true;
        $bytes = file_get_contents($dir . '/' . $file);
        if ($bytes !== false && menu_image_save_to_db($file, menu_image_mime_for($file), $bytes)) {
            $copied++;
        }
    }
} else {
    echo "No uploads/menu-images folder here ($dir).\n";
}
echo "Copied $copied photo file(s) into the database.\n";

// 3. image_url -> file name only
$inDb = array_flip($pdo->query('SELECT filename FROM menu_images')->fetchAll(PDO::FETCH_COLUMN));
$rows = $pdo->query("SELECT id, name, image_url FROM menu_items WHERE image_url IS NOT NULL AND image_url <> ''")->fetchAll();
$update = $pdo->prepare('UPDATE menu_items SET image_url = :u WHERE id = :id');
$fixed = 0;
$missing = [];
foreach ($rows as $row) {
    $name = menu_image_filename($row['image_url']);
    if ($name !== $row['image_url']) {
        $update->execute(['u' => $name, 'id' => $row['id']]);
        $fixed++;
    }
    $isOurs = preg_match('~^' . MENU_IMAGE_NAME_PATTERN . '$~i', (string)$name);
    if ($isOurs && !isset($onDisk[$name]) && !isset($inDb[$name])) {
        $missing[] = $row['name'] . "  ($name)";
    }
}
echo "Updated $fixed image_url value(s) to file names.\n";

// 4. report
if ($missing) {
    echo "\nThese items have NO photo file anywhere — upload their photo again in Admin > Menu > Edit:\n";
    foreach ($missing as $m) {
        echo " - $m\n";
    }
} else {
    echo "\nAll menu photos are accounted for.\n";
}
echo "Done.\n";
