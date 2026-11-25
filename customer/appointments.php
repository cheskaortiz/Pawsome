<?php
require_once __DIR__ . '/../includes/auth.php';
auth_require_customer();
require_once __DIR__ . '/../config/database.php';

$pageTitle = 'Appointments';
$activeCustomerPage = 'appointments';
$customerId = (int) $_SESSION['user_id'];
$appointments = [];
$loadError = false;

try {
    $statement = $conn->prepare(
        'SELECT a.appointment_id, a.appointment_date, a.appointment_time, a.status,
                p.pet_name, s.service_name, s.price
         FROM appointments AS a
         INNER JOIN pets AS p ON p.pet_id = a.pet_id AND p.user_id = a.user_id
         INNER JOIN services AS s ON s.service_id = a.service_id
         WHERE a.user_id = ?
         ORDER BY a.appointment_date DESC, a.appointment_time DESC'
    );
    $statement->bind_param('i', $customerId);
    $statement->execute();
    $appointments = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
} catch (mysqli_sql_exception $exception) {
    error_log('Pawsome customer appointments query failed: ' . $exception->getMessage());
    $loadError = true;
}

require __DIR__ . '/../includes/customer_header.php';
?>
<section class="customer-page-heading">
    <span class="eyebrow">Grooming plans and visits</span>
    <h1>My <span>Appointments</span></h1>
    <p>Appointments connected to your account and your pets.</p>
</section>

<p class="customer-info" role="note">This page currently shows appointment records only; online booking requests are not available yet.</p>

<?php if ($loadError): ?>
    <p class="customer-alert" role="alert">We couldn't load your appointments right now. Please try again later.</p>
<?php elseif (!$appointments): ?>
    <section class="customer-empty-state">
        <span class="customer-empty-mark" aria-hidden="true">♡</span>
        <h2>No appointments yet</h2>
        <p>Your appointment details will appear here when they are available.</p>
    </section>
<?php else: ?>
    <div class="customer-table-wrap">
        <table class="customer-table">
            <thead>
                <tr>
                    <th scope="col">Date and time</th>
                    <th scope="col">Pet</th>
                    <th scope="col">Service</th>
                    <th scope="col">Price</th>
                    <th scope="col">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($appointments as $appointment): ?>
                    <tr>
                        <td>
                            <?= auth_escape(date('M j, Y', strtotime((string) $appointment['appointment_date']))) ?><br>
                            <span class="customer-muted"><?= auth_escape(date('g:i A', strtotime((string) $appointment['appointment_time']))) ?></span>
                        </td>
                        <td><?= auth_escape((string) $appointment['pet_name']) ?></td>
                        <td><?= auth_escape((string) $appointment['service_name']) ?></td>
                        <td>₱<?= auth_escape(number_format((float) $appointment['price'], 2)) ?></td>
                        <td><span class="customer-status"><?= auth_escape((string) ($appointment['status'] ?? 'Pending')) ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/customer_footer.php'; ?>
