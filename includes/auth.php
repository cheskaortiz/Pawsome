<?php
$scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$basePath = dirname($scriptName, 2);
if ($basePath === '/' || $basePath === '.') {
    $basePath = '';
}

if (!defined('PAWSOME_BASE_PATH')) {
    define('PAWSOME_BASE_PATH', $basePath);
}

$cookiePath = PAWSOME_BASE_PATH === '' ? '/' : PAWSOME_BASE_PATH . '/';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $cookiePath,
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function auth_is_logged_in(): bool
{
    return isset($_SESSION['user_id']) && is_int($_SESSION['user_id']);
}

function auth_redirect_logged_in(): void
{
    if (auth_is_logged_in()) {
        $role = $_SESSION['role'] ?? null;
        if ($role === 'admin') {
            $destination = '/admin/dashboard.php';
        } elseif ($role === 'customer') {
            $destination = '/customer/dashboard.php';
        } else {
            $destination = '/index.php';
        }
        header('Location: ' . PAWSOME_BASE_PATH . $destination, true, 303);
        exit;
    }
}

function auth_require_login(): void
{
    if (!auth_is_logged_in()) {
        header('Location: ' . PAWSOME_BASE_PATH . '/customer/login.php', true, 303);
        exit;
    }

    global $conn;
    require_once __DIR__ . '/../config/database.php';

    try {
        $userId = (int) $_SESSION['user_id'];
        $statement = $conn->prepare(
            'SELECT first_name, last_name, username, role, account_status, auth_version
             FROM users
             WHERE user_id = ?
             LIMIT 1'
        );
        $statement->bind_param('i', $userId);
        $statement->execute();
        $user = $statement->get_result()->fetch_assoc();
        $statement->close();
    } catch (mysqli_sql_exception $exception) {
        error_log('Pawsome authenticated account lookup failed: ' . $exception->getMessage());
        http_response_code(503);
        exit('We could not verify your account right now. Please try again later.');
    }

    if (
        !$user
        || $user['account_status'] !== 'Active'
        || !in_array($user['role'], ['admin', 'customer'], true)
        || (int) ($user['auth_version'] ?? 0) !== (int) ($_SESSION['auth_version'] ?? 0)
    ) {
        $_SESSION = [];
        $cookie = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $cookie['path'],
            $cookie['domain'],
            $cookie['secure'],
            $cookie['httponly']
        );
        session_destroy();
        header('Location: ' . PAWSOME_BASE_PATH . '/customer/login.php?session_expired=1', true, 303);
        exit;
    }

    $_SESSION['first_name'] = $user['first_name'];
    $_SESSION['last_name'] = $user['last_name'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['auth_version'] = (int) $user['auth_version'];
}

function auth_require_customer(): void
{
    auth_require_login();

    if (($_SESSION['role'] ?? null) !== 'customer') {
        http_response_code(403);
        exit('This section is available to customer accounts only.');
    }
}

function auth_require_admin(): void
{
    auth_require_login();

    if (($_SESSION['role'] ?? null) !== 'admin') {
        http_response_code(403);
        exit('This section is available to admin accounts only.');
    }
}

function auth_csrf_token(): string
{
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function auth_csrf_is_valid($token): bool
{
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && is_string($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function auth_post_string(string $key): string
{
    return isset($_POST[$key]) && is_string($_POST[$key]) ? $_POST[$key] : '';
}

function auth_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function auth_set_flash(string $key, string $message): void
{
    $_SESSION['auth_flash'][$key] = $message;
}

function auth_take_flash(string $key): ?string
{
    if (!isset($_SESSION['auth_flash'][$key]) || !is_string($_SESSION['auth_flash'][$key])) {
        return null;
    }

    $message = $_SESSION['auth_flash'][$key];
    unset($_SESSION['auth_flash'][$key]);

    return $message;
}

function auth_string_length(string $value): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }

    return strlen($value);
}
