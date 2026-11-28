<?php
require_once __DIR__ . '/../includes/auth.php';
auth_require_customer();
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$respond = static function (int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};
$customerId = (int) $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $countStatement = $conn->prepare(
            'SELECT COUNT(*) AS unread_count
             FROM customer_notifications
             WHERE customer_id = ? AND read_at IS NULL'
        );
        $countStatement->bind_param('i', $customerId);
        $countStatement->execute();
        $unreadCount = (int) $countStatement->get_result()->fetch_assoc()['unread_count'];
        $countStatement->close();

        $statement = $conn->prepare(
            'SELECT notification_id, title, message, target_url, read_at,
                    DATE_FORMAT(created_at, \'%b %e, %Y %l:%i %p\') AS created_label
             FROM customer_notifications
             WHERE customer_id = ?
             ORDER BY created_at DESC, notification_id DESC
             LIMIT 30'
        );
        $statement->bind_param('i', $customerId);
        $statement->execute();
        $notifications = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
        $statement->close();
        $respond(200, ['notifications' => $notifications, 'unread_count' => $unreadCount]);
    } catch (mysqli_sql_exception $exception) {
        error_log('Pawsome customer notifications load failed: ' . $exception->getMessage());
        $respond(503, ['error' => 'Notifications are temporarily unavailable.']);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: GET, POST');
    $respond(405, ['error' => 'This request method is not supported.']);
}

if (!auth_csrf_is_valid($_POST['csrf_token'] ?? null)) {
    $respond(400, ['error' => 'Your session expired. Reload the page and try again.']);
}

$notificationId = filter_var(
    auth_post_string('notification_id'),
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
if ($notificationId === false) {
    $respond(400, ['error' => 'Choose a valid notification.']);
}

try {
    $statement = $conn->prepare(
        'UPDATE customer_notifications
         SET read_at = CURRENT_TIMESTAMP
         WHERE notification_id = ? AND customer_id = ? AND read_at IS NULL'
    );
    $statement->bind_param('ii', $notificationId, $customerId);
    $statement->execute();
    $updated = $statement->affected_rows;
    $statement->close();

    if ($updated === 0) {
        $checkStatement = $conn->prepare(
            'SELECT notification_id
             FROM customer_notifications
             WHERE notification_id = ? AND customer_id = ?
             LIMIT 1'
        );
        $checkStatement->bind_param('ii', $notificationId, $customerId);
        $checkStatement->execute();
        $exists = $checkStatement->get_result()->fetch_assoc();
        $checkStatement->close();
        if (!$exists) {
            $respond(404, ['error' => 'This notification could not be found.']);
        }
    }

    $respond(200, ['success' => true]);
} catch (mysqli_sql_exception $exception) {
    error_log('Pawsome customer notification update failed: ' . $exception->getMessage());
    $respond(503, ['error' => 'We could not update this notification. Please try again.']);
}