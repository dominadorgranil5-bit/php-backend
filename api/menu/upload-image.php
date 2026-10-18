<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_method('POST');
require_role(['staff', 'admin']);

if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    json_error('No file uploaded (expected multipart field "file").', 400);
}

$file = $_FILES['file'];

$allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!isset($allowed[$mime])) {
    json_error('Only JPEG or PNG images are allowed.', 400);
}
if ($file['size'] > 5 * 1024 * 1024) {
    json_error('Image must be 5MB or smaller.', 400);
}

$ext = $allowed[$mime];
$filename = time() . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
$destDir = __DIR__ . '/../../uploads/menu-images';
if (!is_dir($destDir)) {
    mkdir($destDir, 0755, true);
}
$destPath = $destDir . '/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    json_error('Could not save uploaded file.', 500);
}

$baseUrl = rtrim(config('UPLOADS_BASE_URL', ''), '/');
$publicUrl = $baseUrl !== '' ? "$baseUrl/$filename" : "/uploads/menu-images/$filename";

json_out(['url' => $publicUrl], 201);
