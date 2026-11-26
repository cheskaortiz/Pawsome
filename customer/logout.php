<?php
require_once __DIR__ . '/../includes/auth.php';

if (!auth_is_logged_in()) {
    header('Location: login.php', true, 303);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed.');
}

if (!auth_csrf_is_valid($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    exit('Your session expired. Please return to your account and try again.');
}

$role = $_SESSION['role'] ?? '';
$firstName = $_SESSION['first_name'] ?? '';
$lastName = $_SESSION['last_name'] ?? '';
if (in_array($role, ['admin', 'customer'], true) && is_string($firstName) && is_string($lastName)) {
    $userName = trim($firstName . ' ' . $lastName);
    if ($userName === '') {
        $userName = 'Unknown user';
    }

    try {
        require_once __DIR__ . '/../config/database.php';
        require_once __DIR__ . '/../includes/activity_log.php';
        activity_log_record(
            $conn,
            (int) $_SESSION['user_id'],
            $userName,
            $role,
            'auth.signed_out',
            'Signed out successfully.'
        );
    } catch (mysqli_sql_exception $exception) {
        error_log('Pawsome sign-out activity logging failed: ' . $exception->getMessage());
    }
}

$_SESSION = [];
$cookie = session_get_cookie_params();
setcookie(session_name(), '', time() - 42000, $cookie['path'], $cookie['domain'], $cookie['secure'], $cookie['httponly']);
session_destroy();

header('Location: login.php?logged_out=1', true, 303);
exit;
