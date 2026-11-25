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

$_SESSION = [];
$cookie = session_get_cookie_params();
setcookie(session_name(), '', time() - 42000, $cookie['path'], $cookie['domain'], $cookie['secure'], $cookie['httponly']);
session_destroy();

header('Location: login.php?logged_out=1', true, 303);
exit;
