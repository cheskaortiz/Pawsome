<?php
require_once __DIR__ . '/../includes/auth.php';
auth_require_admin();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/activity_log.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$respond = static function (int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};
$userId = (int) $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $statement = $conn->prepare(
            'SELECT first_name, last_name, email, username, phone
             FROM users
             WHERE user_id = ? AND role = \'admin\'
             LIMIT 1'
        );
        $statement->bind_param('i', $userId);
        $statement->execute();
        $profile = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!$profile) {
            $respond(404, ['error' => 'The administrator profile could not be found.']);
        }
        $respond(200, ['profile' => $profile]);
    } catch (mysqli_sql_exception $exception) {
        error_log('Pawsome admin profile lookup failed: ' . $exception->getMessage());
        $respond(503, ['error' => 'We could not load your profile right now.']);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: GET, POST');
    $respond(405, ['error' => 'This request method is not supported.']);
}

if (!auth_csrf_is_valid($_POST['csrf_token'] ?? null)) {
    $respond(400, ['error' => 'Your session expired. Reload the page and try again.']);
}

$profile = [
    'first_name' => trim(auth_post_string('first_name')),
    'last_name' => trim(auth_post_string('last_name')),
    'email' => trim(auth_post_string('email')),
    'username' => trim(auth_post_string('username')),
    'phone' => trim(auth_post_string('phone')),
];
$validProfile = $profile['first_name'] !== ''
    && auth_string_length($profile['first_name']) <= 100
    && $profile['last_name'] !== ''
    && auth_string_length($profile['last_name']) <= 100
    && $profile['email'] !== ''
    && auth_string_length($profile['email']) <= 150
    && filter_var($profile['email'], FILTER_VALIDATE_EMAIL) !== false
    && $profile['username'] !== ''
    && auth_string_length($profile['username']) <= 50
    && auth_string_length($profile['phone']) <= 20;
if (!$validProfile) {
    $respond(422, ['error' => 'Check the required fields, email address, and allowed field lengths.']);
}

$phone = $profile['phone'] === '' ? null : $profile['phone'];
$transactionStarted = false;
try {
    $statement = $conn->prepare(
        'SELECT first_name, last_name, email, username, phone
         FROM users
         WHERE user_id = ? AND role = \'admin\'
         FOR UPDATE'
    );
    $conn->begin_transaction();
    $transactionStarted = true;
    $statement->bind_param('i', $userId);
    $statement->execute();
    $existingProfile = $statement->get_result()->fetch_assoc();
    $statement->close();
    if (!$existingProfile) {
        $conn->rollback();
        $transactionStarted = false;
        $respond(404, ['error' => 'The administrator profile could not be found.']);
    }

    $changedFields = [];
    foreach (['first_name', 'last_name', 'email', 'username'] as $field) {
        if ((string) $existingProfile[$field] !== $profile[$field]) {
            $changedFields[] = $field;
        }
    }
    if ((string) ($existingProfile['phone'] ?? '') !== (string) ($phone ?? '')) {
        $changedFields[] = 'phone';
    }
    if ($changedFields !== []) {
        $statement = $conn->prepare(
            'UPDATE users
             SET first_name = ?, last_name = ?, email = ?, username = ?, phone = ?
             WHERE user_id = ? AND role = \'admin\''
        );
        $statement->bind_param(
            'sssssi',
            $profile['first_name'],
            $profile['last_name'],
            $profile['email'],
            $profile['username'],
            $phone,
            $userId
        );
        $statement->execute();
        $statement->close();
        activity_log_current_user(
            $conn,
            'account.updated',
            'Updated their administrator profile.',
            ['account_id' => $userId, 'changed_fields' => $changedFields]
        );
    }

    $conn->commit();
    $transactionStarted = false;
    $_SESSION['first_name'] = $profile['first_name'];
    $_SESSION['last_name'] = $profile['last_name'];
    $_SESSION['username'] = $profile['username'];
    $respond(200, ['success' => true, 'message' => 'Your profile was saved.']);
} catch (mysqli_sql_exception $exception) {
    if ($transactionStarted) {
        $conn->rollback();
    }
    if ((int) $exception->getCode() === 1062) {
        $respond(409, ['error' => 'That email address or username is already in use.']);
    }
    error_log('Pawsome admin profile update failed: ' . $exception->getMessage());
    $respond(503, ['error' => 'We could not save your profile right now. Please try again.']);
}
