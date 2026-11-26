<?php

function activity_log_record(
    mysqli $conn,
    int $userId,
    string $userName,
    string $role,
    string $actionType,
    string $description,
    array $details = []
): void {
    if (!in_array($role, ['admin', 'customer'], true)) {
        throw new InvalidArgumentException('Activity log role is invalid.');
    }

    if (!preg_match('/\A[a-z][a-z0-9._-]{0,63}\z/', $actionType)) {
        throw new InvalidArgumentException('Activity log action type is invalid.');
    }

    $detailsJson = $details === []
        ? null
        : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

    if ($details !== [] && $detailsJson === false) {
        throw new RuntimeException('Activity details could not be encoded.');
    }

    $statement = $conn->prepare(
        'INSERT INTO activity_logs (user_id, user_name, role, action_type, description, details_json)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $statement->bind_param('isssss', $userId, $userName, $role, $actionType, $description, $detailsJson);
    $statement->execute();
    $statement->close();
}

function activity_log_current_user(
    mysqli $conn,
    string $actionType,
    string $description,
    array $details = []
): void {
    $userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
    $role = $_SESSION['role'] ?? '';
    $firstName = $_SESSION['first_name'] ?? '';
    $lastName = $_SESSION['last_name'] ?? '';

    if ($userId === false || $userId === null || !is_string($firstName) || !is_string($lastName)) {
        throw new LogicException('The current user cannot be identified for activity logging.');
    }

    $userName = trim($firstName . ' ' . $lastName);
    if ($userName === '') {
        $userName = 'Unknown user';
    }

    activity_log_record($conn, (int) $userId, $userName, (string) $role, $actionType, $description, $details);
}
