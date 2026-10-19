<?php
/**
 * lib/menu_images.php — menu photo storage that survives IP changes, server moves and lost folders.
 *
 * Problems this fixes:
 *  - image_url used to be saved as a FULL URL with the laptop's LAN IP (e.g. http://192.168.11.138/...),
 *    so photos broke as soon as the IP changed, on the phone, on Vercel or on GoogieHost.
 *  - The files only lived in uploads/menu-images (git-ignored, never copied with the database).
 *
 * Now:
 *  - menu_items.image_url stores only the FILE NAME (e.g. 1760000000-ab12cd34ef56.jpg).
 *  - Every response turns that into a URL of the server that is answering the request
 *    (api/menu/image.php?f=<file>), so the same database works locally and online.
 *  - Each uploaded photo is saved BOTH as a file and inside the menu_images table, and
 *    api/menu/image.php re-creates the file from the table if it ever goes missing.
 */

const MENU_IMAGE_NAME_PATTERN = '[A-Za-z0-9][A-Za-z0-9._-]{0,150}\.(?:jpe?g|png|webp|gif)';

function menu_images_dir(): string
{
    return __DIR__ . '/../uploads/menu-images';
}

function menu_images_ensure_table(): void
{
    db()->exec(
        'CREATE TABLE IF NOT EXISTS menu_images (
           filename   VARCHAR(160) NOT NULL PRIMARY KEY,
           mime       VARCHAR(40)  NOT NULL,
           data       MEDIUMBLOB   NOT NULL,
           created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function menu_image_mime_for(string $filename): string
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return match ($ext) {
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        default => 'image/jpeg',
    };
}

/** Saves a photo's bytes in the database (best effort — the file on disk is still the primary copy). */
function menu_image_save_to_db(string $filename, string $mime, string $bytes): bool
{
    try {
        menu_images_ensure_table();
        $stmt = db()->prepare(
            'INSERT INTO menu_images (filename, mime, data) VALUES (:f, :m, :d)
             ON DUPLICATE KEY UPDATE mime = VALUES(mime), data = VALUES(data)'
        );
        $stmt->bindValue(':f', $filename);
        $stmt->bindValue(':m', $mime);
        $stmt->bindValue(':d', $bytes, PDO::PARAM_LOB);
        $stmt->execute();
        return true;
    } catch (Throwable $e) {
        error_log('[menu_images] could not store photo in DB: ' . $e->getMessage());
        return false;
    }
}

/**
 * Whatever the app sends back (a full old URL, an image.php URL or a bare name) -> the bare file name.
 * Anything that is not one of our photos (e.g. an external https image) is returned unchanged.
 */
function menu_image_filename(?string $value): ?string
{
    if ($value === null) {
        return null;
    }
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    if (preg_match('~^' . MENU_IMAGE_NAME_PATTERN . '$~i', $value)) {
        return $value;
    }
    if (preg_match('~(?:menu-images/|[?&]f=)(' . MENU_IMAGE_NAME_PATTERN . ')(?:[?&#]|$)~i', $value, $m)) {
        return $m[1];
    }

    // Legacy rows written by older builds: a full uploads/ URL that the old
    // image_url column (varchar(80)) cut short — the stray space after the
    // scheme plus the missing last letter, e.g. ".../1792310151-0ca806b3495e.jp".
    // Pull the name out anyway and grow the truncated extension back to a real one.
    if (preg_match('~(?:menu-images/|[?&]f=)([A-Za-z0-9][A-Za-z0-9._-]*)~i', $value, $m)) {
        $candidate = str_replace(' ', '', $m[1]);
        $ext = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
        if ($ext !== '') {
            $stem = substr($candidate, 0, -strlen($ext) - 1);
            foreach (['jpg', 'png', 'webp', 'gif'] as $full) {
                if (str_starts_with($full, $ext)) {
                    $candidate = $stem . '.' . $full;
                    break;
                }
            }
        }
        if (preg_match('~^' . MENU_IMAGE_NAME_PATTERN . '$~i', $candidate)) {
            return $candidate;
        }
    }

    return $value;
}

/** URL of THIS server's api/menu/image.php for a stored file name. */
function menu_image_public_url(?string $stored): ?string
{
    $name = menu_image_filename($stored);
    if ($name === null || !preg_match('~^' . MENU_IMAGE_NAME_PATTERN . '$~i', $name)) {
        return $name; // null or an external URL — leave as is
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = $_SERVER['SCRIPT_NAME'] ?? '/api/menu/all.php';
    $apiPos = strpos($script, '/api/');
    $prefix = $apiPos === false ? '' : substr($script, 0, $apiPos);
    return ($https ? 'https' : 'http') . '://' . $host . $prefix . '/api/menu/image.php?f=' . rawurlencode($name);
}

/** Rewrites image_url on a list of menu item rows into this server's public URL. */
function menu_images_resolve(array $items): array
{
    foreach ($items as &$item) {
        if (is_array($item) && array_key_exists('image_url', $item)) {
            $item['image_url'] = menu_image_public_url($item['image_url']);
        }
    }
    unset($item);
    return $items;
}
