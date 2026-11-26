<?php
require_once __DIR__ . '/../includes/auth.php';
auth_require_customer();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/appointment_price.php';

$pageTitle = 'Appointments';
$activeCustomerPage = 'appointments';
$customerId = (int) $_SESSION['user_id'];
$appointments = [];
$loadError = false;
$successMessage = auth_take_flash('appointment_success');
$errorMessage = auth_take_flash('appointment_error');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!auth_csrf_is_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        exit('Your session expired. Please reload the page and try again.');
    }

    $rawAppointmentId = auth_post_string('appointment_id');
    $appointmentId = filter_var($rawAppointmentId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($appointmentId === false) {
        http_response_code(400);
        exit('A valid appointment is required.');
    }

    try {
        $statement = $conn->prepare(
            "UPDATE appointments AS a
             SET a.status = 'Cancelled'
             WHERE a.appointment_id = ?
               AND a.user_id = ?
               AND a.status IN ('Pending', 'Confirmed')
               AND NOT EXISTS (
                   SELECT 1
                   FROM grooming_records AS gr
                   WHERE gr.appointment_id = a.appointment_id
               )"
        );
        $statement->bind_param('ii', $appointmentId, $customerId);
        $statement->execute();
        $cancelled = $statement->affected_rows === 1;
        $statement->close();

        if ($cancelled) {
            auth_set_flash('appointment_success', 'Your appointment has been cancelled.');
        } else {
            auth_set_flash('appointment_error', 'This appointment can no longer be cancelled.');
        }
    } catch (mysqli_sql_exception $exception) {
        error_log('Pawsome customer appointment cancellation failed: ' . $exception->getMessage());
        auth_set_flash('appointment_error', 'We could not cancel this appointment right now. Please try again.');
    }

    header('Location: appointments.php', true, 303);
    exit;
}

try {
    $statement = $conn->prepare(
        "SELECT a.appointment_id, a.appointment_date, a.appointment_time, a.status,
            p.pet_name, s.service_name, s.price AS current_service_price,
            a.service_price_at_booking, a.service_price_snapshot_saved,
                CASE
                    WHEN a.status IN ('Pending', 'Confirmed')
                     AND NOT EXISTS (
                         SELECT 1
                         FROM grooming_records AS gr
                         WHERE gr.appointment_id = a.appointment_id
                     )
                    THEN 1
                    ELSE 0
                END AS can_cancel
         FROM appointments AS a
         INNER JOIN pets AS p ON p.pet_id = a.pet_id AND p.user_id = a.user_id
         INNER JOIN services AS s ON s.service_id = a.service_id
         WHERE a.user_id = ?
         ORDER BY a.appointment_date DESC, a.appointment_time DESC"
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

<?php if ($successMessage !== null): ?>
    <p class="customer-success" role="status"><?= auth_escape($successMessage) ?></p>
<?php endif; ?>
<?php if ($errorMessage !== null): ?>
    <p class="customer-alert" role="alert"><?= auth_escape($errorMessage) ?></p>
<?php endif; ?>

<?php if ($loadError): ?>
    <p class="customer-alert" role="alert">We couldn't load your appointments right now. Please try again later.</p>
<?php elseif (!$appointments): ?>
    <section class="customer-empty-state">
        <span class="customer-empty-mark" aria-hidden="true">♡</span>
        <h2>No appointments yet</h2>
        <p>When you have a grooming appointment, its details and status will appear here.</p>
    </section>
<?php else: ?>
    <div class="customer-table-wrap">
        <table class="customer-table">
            <thead>
                <tr>
                    <th scope="col">Reference</th>
                    <th scope="col">Date and time</th>
                    <th scope="col">Pet</th>
                    <th scope="col">Service</th>
                    <th scope="col">Total price</th>
                    <th scope="col">Status</th>
                    <th scope="col">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($appointments as $appointment): ?>
                    <?php
                    $status = (string) ($appointment['status'] ?? '');
                    $statusClasses = [
                        'Pending' => 'pending',
                        'Confirmed' => 'confirmed',
                        'In Progress' => 'in-progress',
                        'Ready for Pickup' => 'ready-for-pickup',
                        'Completed' => 'completed',
                        'Cancelled' => 'cancelled',
                        'Rejected' => 'rejected',
                    ];
                    $statusLabel = isset($statusClasses[$status]) ? $status : 'Status unavailable';
                    $statusClass = $statusClasses[$status] ?? 'unknown';
                    $priceDisplay = appointment_price_display($appointment);
                    ?>
                    <tr>
                        <td>#<?= (int) $appointment['appointment_id'] ?></td>
                        <td>
                            <?= auth_escape(date('M j, Y', strtotime((string) $appointment['appointment_date']))) ?><br>
                            <span class="customer-muted"><?= auth_escape(date('g:i A', strtotime((string) $appointment['appointment_time']))) ?></span>
                        </td>
                        <td><?= auth_escape((string) $appointment['pet_name']) ?></td>
                        <td><?= auth_escape((string) $appointment['service_name']) ?></td>
                        <td>
                            <?= auth_escape($priceDisplay['formatted']) ?>
                            <span class="customer-muted"><?= auth_escape($priceDisplay['source']) ?></span>
                        </td>
                        <td><span class="customer-status customer-status--<?= auth_escape($statusClass) ?>"><?= auth_escape($statusLabel) ?></span></td>
                        <td>
                            <?php if ((int) $appointment['can_cancel'] === 1): ?>
                                <form method="post" action="appointments.php" onsubmit="return confirm('Cancel this appointment?');">
                                    <input type="hidden" name="csrf_token" value="<?= auth_escape(auth_csrf_token()) ?>">
                                    <input type="hidden" name="appointment_id" value="<?= (int) $appointment['appointment_id'] ?>">
                                    <button class="appointment-cancel-button" type="submit">Cancel</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="customer-muted customer-price-note">New appointments show the price saved when booking. For older appointments without a saved price, the current service price is shown as a fallback and may not match the original amount.</p>
<?php endif; ?>
<?php require __DIR__ . '/../includes/customer_footer.php'; ?>
