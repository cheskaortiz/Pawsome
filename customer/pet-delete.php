<?php
require_once __DIR__ . '/../includes/auth.php';
auth_require_customer();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/pet_uploads.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed.');
}

if (!auth_csrf_is_valid($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    exit('Your session expired. Please reload the page and try again.');
}

$rawPetId = auth_post_string('pet_id');
$petId = filter_var($rawPetId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($petId === false) {
    http_response_code(400);
    exit('A valid pet profile is required.');
}

$customerId = (int) $_SESSION['user_id'];

try {
    $lookup = $conn->prepare(
        'SELECT photo_path, vaccination_proof_path
         FROM pets
         WHERE pet_id = ? AND user_id = ?
         LIMIT 1'
    );
    $lookup->bind_param('ii', $petId, $customerId);
    $lookup->execute();
    $pet = $lookup->get_result()->fetch_assoc();
    $lookup->close();

    if (!$pet) {
        http_response_code(404);
        exit('Pet profile not found.');
    }

    $delete = $conn->prepare('DELETE FROM pets WHERE pet_id = ? AND user_id = ?');
    $delete->bind_param('ii', $petId, $customerId);
    $delete->execute();
    $deleted = $delete->affected_rows === 1;
    $delete->close();

    if (!$deleted) {
        http_response_code(404);
        exit('Pet profile not found.');
    }
} catch (mysqli_sql_exception $exception) {
    if ((int) $exception->getCode() === 1451) {
        auth_set_flash('pet_error', 'This pet is linked to appointment or grooming records and cannot be deleted.');
    } else {
        error_log('Pawsome pet profile deletion failed: ' . $exception->getMessage());
        auth_set_flash('pet_error', 'We could not delete this pet profile right now. Please try again.');
    }
    header('Location: pets.php', true, 303);
    exit;
}

$photoRemoved = pet_remove_uploaded_file($pet['photo_path']);
$proofRemoved = pet_remove_uploaded_file($pet['vaccination_proof_path']);
if (!$photoRemoved || !$proofRemoved) {
    error_log('Pawsome could not remove one or more files for a deleted pet profile.');
    auth_set_flash('pet_error', 'The pet profile was deleted, but an uploaded file could not be removed.');
} else {
    auth_set_flash('pet_success', 'Pet profile deleted successfully.');
}

header('Location: pets.php', true, 303);
exit;
