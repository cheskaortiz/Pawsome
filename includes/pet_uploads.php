<?php
define('PET_UPLOAD_DIRECTORY', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'pets');
define('PET_PHOTO_MAX_BYTES', 5 * 1024 * 1024);
define('PET_PROOF_MAX_BYTES', 10 * 1024 * 1024);

function pet_store_upload($file, string $kind): array
{
    if ($file === null) {
        return ['path' => null, 'error' => null];
    }

    if (!is_array($file) || !isset($file['error']) || !is_numeric($file['error'])) {
        return ['path' => null, 'error' => 'The selected file could not be processed.'];
    }

    $uploadError = (int) $file['error'];
    if ($uploadError === UPLOAD_ERR_NO_FILE) {
        return ['path' => null, 'error' => null];
    }

    if ($uploadError !== UPLOAD_ERR_OK) {
        $message = $uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE
            ? 'The selected file exceeds the server upload limit.'
            : 'The selected file could not be uploaded. Please try again.';
        return ['path' => null, 'error' => $message];
    }

    if (
        !isset($file['tmp_name'], $file['size'])
        || !is_string($file['tmp_name'])
        || !is_numeric($file['size'])
        || !is_uploaded_file($file['tmp_name'])
    ) {
        return ['path' => null, 'error' => 'The selected file could not be processed.'];
    }

    $isPhoto = $kind === 'photo';
    if (!$isPhoto && $kind !== 'vaccination') {
        return ['path' => null, 'error' => 'The selected upload type is invalid.'];
    }

    $maximumSize = $isPhoto ? PET_PHOTO_MAX_BYTES : PET_PROOF_MAX_BYTES;
    if ((int) $file['size'] <= 0 || (int) $file['size'] > $maximumSize) {
        $maximumMegabytes = (int) ($maximumSize / (1024 * 1024));
        return [
            'path' => null,
            'error' => 'Choose a file smaller than ' . $maximumMegabytes . ' MB.',
        ];
    }

    $allowedTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if (!$isPhoto) {
        $allowedTypes['application/pdf'] = 'pdf';
    }

    $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($fileInfo === false) {
        return ['path' => null, 'error' => 'File validation is unavailable. Please try again later.'];
    }

    $mimeType = finfo_file($fileInfo, $file['tmp_name']);
    finfo_close($fileInfo);

    if (!is_string($mimeType) || !isset($allowedTypes[$mimeType])) {
        return [
            'path' => null,
            'error' => $isPhoto
                ? 'Pet photos must be JPG, PNG, or WebP images.'
                : 'Vaccination proof must be a JPG, PNG, WebP image, or PDF.',
        ];
    }

    if (strpos($mimeType, 'image/') === 0) {
        $image = getimagesize($file['tmp_name']);
        if ($image === false || ($image['mime'] ?? '') !== $mimeType) {
            return ['path' => null, 'error' => 'The selected image is invalid.'];
        }
    } elseif ($mimeType === 'application/pdf') {
        $handle = fopen($file['tmp_name'], 'rb');
        $signature = $handle === false ? false : fread($handle, 5);
        if ($handle !== false) {
            fclose($handle);
        }
        if ($signature !== '%PDF-') {
            return ['path' => null, 'error' => 'The selected PDF is invalid.'];
        }
    }

    if (is_link(PET_UPLOAD_DIRECTORY)) {
        return ['path' => null, 'error' => 'Secure file storage is unavailable. Please try again later.'];
    }

    if (!is_dir(PET_UPLOAD_DIRECTORY) && !mkdir(PET_UPLOAD_DIRECTORY, 0750, true) && !is_dir(PET_UPLOAD_DIRECTORY)) {
        return ['path' => null, 'error' => 'Secure file storage is unavailable. Please try again later.'];
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $allowedTypes[$mimeType];
    $destination = PET_UPLOAD_DIRECTORY . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        return ['path' => null, 'error' => 'The selected file could not be saved. Please try again.'];
    }

    return ['path' => $filename, 'error' => null];
}

function pet_uploaded_file_path($filename): ?string
{
    if (!is_string($filename) || !preg_match('/\A[a-f0-9]{32}\.(?:jpg|png|webp|pdf)\z/D', $filename)) {
        return null;
    }

    return PET_UPLOAD_DIRECTORY . DIRECTORY_SEPARATOR . $filename;
}

function pet_remove_uploaded_file($filename): bool
{
    $path = pet_uploaded_file_path($filename);
    if ($path === null) {
        return $filename === null || $filename === '';
    }

    return !is_file($path) || unlink($path);
}
