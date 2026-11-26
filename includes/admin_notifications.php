<?php

function admin_notification_broadcast(
    mysqli $conn,
    string $type,
    string $title,
    string $message,
    string $targetUrl
): void {
    if (!in_array($type, ['appointment.booked', 'appointment.cancelled', 'appointment.status_changed', 'review.submitted'], true)) {
        throw new InvalidArgumentException('Admin notification type is invalid.');
    }

    if (!preg_match('/\A(?:appointments\.php|appointment-details\.php\?id=[1-9][0-9]*|reviews\.php)\z/D', $targetUrl)) {
        throw new InvalidArgumentException('Admin notification destination is invalid.');
    }

    $statement = $conn->prepare(
        'INSERT INTO admin_notifications (admin_id, notification_type, title, message, target_url)
         SELECT user_id, ?, ?, ?, ?
         FROM users
         WHERE role = \'admin\' AND account_status = \'Active\''
    );
    $statement->bind_param('ssss', $type, $title, $message, $targetUrl);
    $statement->execute();
    $statement->close();
}
