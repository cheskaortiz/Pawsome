<?php
define('STAFF_UPLOAD_DIRECTORY', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'staff');
define('STAFF_PHOTO_MAX_BYTES', 5 * 1024 * 1024);

function staff_store_profile_photo($file): array
{
    if ($file === null) {
        return ['filename' => null, 'error' => null];
    }

    if (!is_array($file) || !isset($file['error']) || !is_numeric($file['error'])) {
        return ['filename' => null, 'error' => 'The selected photo could not be processed.'];
    }

    $uploadError = (int) $file['error'];
    if ($uploadError === UPLOAD_ERR_NO_FILE) {
        return ['filename' => null, 'error' => null];
    }
    if ($uploadError !== UPLOAD_ERR_OK) {
        $message = $uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE
            ? 'The selected photo exceeds the server upload limit.'
            : 'The selected photo could not be uploaded. Please try again.';
        return ['filename' => null, 'error' => $message];
    }

    if (
        !isset($file['tmp_name'], $file['size'])
        || !is_string($file['tmp_name'])
        || !is_numeric($file['size'])
        || !is_uploaded_file($file['tmp_name'])
    ) {
        return ['filename' => null, 'error' => 'The selected photo could not be processed.'];
    }

    if ((int) $file['size'] <= 0 || (int) $file['size'] > STAFF_PHOTO_MAX_BYTES) {
        return ['filename' => null, 'error' => 'Choose a photo smaller than 5 MB.'];
    }

    $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($fileInfo === false) {
        return ['filename' => null, 'error' => 'Photo validation is unavailable. Please try again later.'];
    }
    $mimeType = finfo_file($fileInfo, $file['tmp_name']);
    finfo_close($fileInfo);

    $allowedTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if (!is_string($mimeType) || !isset($allowedTypes[$mimeType])) {
        return ['filename' => null, 'error' => 'Staff photos must be JPG, PNG, or WebP images.'];
    }

    $image = getimagesize($file['tmp_name']);
    if ($image === false || ($image['mime'] ?? '') !== $mimeType) {
        return ['filename' => null, 'error' => 'The selected photo is invalid.'];
    }

    if (is_link(STAFF_UPLOAD_DIRECTORY)) {
        return ['filename' => null, 'error' => 'Secure photo storage is unavailable. Please try again later.'];
    }
    if (!is_dir(STAFF_UPLOAD_DIRECTORY) && !mkdir(STAFF_UPLOAD_DIRECTORY, 0750, true) && !is_dir(STAFF_UPLOAD_DIRECTORY)) {
        return ['filename' => null, 'error' => 'Secure photo storage is unavailable. Please try again later.'];
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $allowedTypes[$mimeType];
    $destination = STAFF_UPLOAD_DIRECTORY . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        return ['filename' => null, 'error' => 'The selected photo could not be saved. Please try again.'];
    }

    return ['filename' => $filename, 'error' => null];
}

function staff_profile_photo_path($filename): ?string
{
    if (!is_string($filename) || !preg_match('/\A[a-f0-9]{32}\.(?:jpg|png|webp)\z/D', $filename)) {
        return null;
    }

    return STAFF_UPLOAD_DIRECTORY . DIRECTORY_SEPARATOR . $filename;
}

function staff_remove_profile_photo($filename): bool
{
    $path = staff_profile_photo_path($filename);
    if ($path === null) {
        return $filename === null || $filename === '';
    }

    return !is_file($path) || unlink($path);
}
