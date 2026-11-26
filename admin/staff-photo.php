<?php
require_once __DIR__ . '/../includes/auth.php';
auth_require_admin();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/staff_uploads.php';

$rawStaffId = $_GET['id'] ?? '';
$staffId = is_string($rawStaffId)
    ? filter_var($rawStaffId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;
if ($staffId === false) {
    http_response_code(404);
    exit('Photo not found.');
}

try {
    $statement = $conn->prepare('SELECT profile_photo_path FROM staff WHERE staff_id = ? LIMIT 1');
    $statement->bind_param('i', $staffId);
    $statement->execute();
    $staff = $statement->get_result()->fetch_assoc();
    $statement->close();
} catch (mysqli_sql_exception $exception) {
    error_log('Pawsome admin staff photo lookup failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('We could not load this photo right now.');
}

$path = $staff ? staff_profile_photo_path($staff['profile_photo_path']) : null;
$uploadDirectory = realpath(STAFF_UPLOAD_DIRECTORY);
$realPath = $path === null ? false : realpath($path);
if (
    $path === null
    || $uploadDirectory === false
    || $realPath === false
    || strcasecmp(dirname($realPath), $uploadDirectory) !== 0
    || is_link($path)
    || !is_file($realPath)
    || !is_readable($realPath)
) {
    http_response_code(404);
    exit('Photo not found.');
}

$extension = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
$expectedMimeTypes = [
    'jpg' => 'image/jpeg',
    'png' => 'image/png',
    'webp' => 'image/webp',
];
if (!isset($expectedMimeTypes[$extension])) {
    http_response_code(404);
    exit('Photo not found.');
}

$fileInfo = finfo_open(FILEINFO_MIME_TYPE);
if ($fileInfo === false) {
    http_response_code(500);
    exit('Photo validation is unavailable.');
}
$mimeType = finfo_file($fileInfo, $realPath);
finfo_close($fileInfo);
if (!is_string($mimeType) || $mimeType !== $expectedMimeTypes[$extension]) {
    http_response_code(404);
    exit('Photo not found.');
}

$fileSize = filesize($realPath);
if ($fileSize === false) {
    http_response_code(404);
    exit('Photo not found.');
}

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . $fileSize);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Content-Disposition: inline; filename="staff-profile.' . $extension . '"');
readfile($realPath);
