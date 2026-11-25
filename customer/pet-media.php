<?php
require_once __DIR__ . '/../includes/auth.php';
auth_require_customer();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/pet_uploads.php';

$rawPetId = $_GET['pet_id'] ?? '';
$petId = is_string($rawPetId)
    ? filter_var($rawPetId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;
$type = $_GET['type'] ?? '';

if ($petId === false || !is_string($type) || !in_array($type, ['photo', 'vaccination'], true)) {
    http_response_code(404);
    exit('File not found.');
}

$customerId = (int) $_SESSION['user_id'];
try {
    $statement = $conn->prepare(
        'SELECT photo_path, vaccination_proof_path
         FROM pets
         WHERE pet_id = ? AND user_id = ?
         LIMIT 1'
    );
    $statement->bind_param('ii', $petId, $customerId);
    $statement->execute();
    $pet = $statement->get_result()->fetch_assoc();
    $statement->close();
} catch (mysqli_sql_exception $exception) {
    error_log('Pawsome pet file lookup failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('We could not load this file right now.');
}

if (!$pet) {
    http_response_code(404);
    exit('File not found.');
}

$filename = $type === 'photo' ? $pet['photo_path'] : $pet['vaccination_proof_path'];
$path = pet_uploaded_file_path($filename);
$uploadDirectory = realpath(PET_UPLOAD_DIRECTORY);
$realPath = $path === null ? false : realpath($path);

if (
    $path === null
    || $uploadDirectory === false
    || $realPath === false
    || dirname($realPath) !== $uploadDirectory
    || is_link($path)
    || !is_file($realPath)
    || !is_readable($realPath)
) {
    http_response_code(404);
    exit('File not found.');
}

$extension = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
$expectedMimeTypes = [
    'jpg' => 'image/jpeg',
    'png' => 'image/png',
    'webp' => 'image/webp',
    'pdf' => 'application/pdf',
];
if (!isset($expectedMimeTypes[$extension]) || ($type === 'photo' && $extension === 'pdf')) {
    http_response_code(404);
    exit('File not found.');
}

$fileInfo = finfo_open(FILEINFO_MIME_TYPE);
if ($fileInfo === false) {
    http_response_code(500);
    exit('File validation is unavailable.');
}
$mimeType = finfo_file($fileInfo, $realPath);
finfo_close($fileInfo);

if (!is_string($mimeType) || $mimeType !== $expectedMimeTypes[$extension]) {
    http_response_code(404);
    exit('File not found.');
}

$fileSize = filesize($realPath);
if ($fileSize === false) {
    http_response_code(404);
    exit('File not found.');
}

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . $fileSize);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Content-Disposition: ' . ($type === 'photo' ? 'inline' : 'attachment') . '; filename="' . ($type === 'photo' ? 'pet-photo.' : 'vaccination-proof.') . $extension . '"');
readfile($realPath);
