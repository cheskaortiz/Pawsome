<?php
require_once __DIR__ . '/../includes/auth.php';
auth_require_admin();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/appointment_price.php';

$activeAdminPage = 'appointments';
$adminFirstName = (string) ($_SESSION['first_name'] ?? 'admin');
$manilaTimezone = new DateTimeZone('Asia/Manila');
$actionTransitions = require __DIR__ . '/../includes/admin_appointment_workflow.php';
$rawAppointmentId = $_GET['id'] ?? '';
$appointmentId = is_string($rawAppointmentId)
    ? filter_var($rawAppointmentId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;

if ($appointmentId === false) {
    http_response_code(404);
    exit('Appointment not found.');
}

$returnUrl = 'appointments.php';
$rawReturnUrl = $_GET['return'] ?? '';
if (is_string($rawReturnUrl)) {
    $returnParts = parse_url($rawReturnUrl);
    if (
        is_array($returnParts)
        && ($returnParts['path'] ?? '') === 'appointments.php'
        && !isset($returnParts['scheme'])
        && !isset($returnParts['host'])
        && !isset($returnParts['user'])
        && !isset($returnParts['pass'])
        && preg_match('/\A[a-zA-Z0-9_%&=+.-]*\z/D', (string) ($returnParts['query'] ?? ''))
    ) {
        $returnUrl .= !empty($returnParts['query']) ? '?' . $returnParts['query'] : '';
    }
}
$detailsUrl = 'appointment-details.php?id=' . (int) $appointmentId
    . ($returnUrl !== 'appointments.php' ? '&return=' . rawurlencode($returnUrl) : '');

$validationError = null;
$postedPickupDate = null;
$postedPickupTime = null;
$postedAdminNotes = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!auth_csrf_is_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        exit('Your session expired. Please reload the page and try again.');
    }

    if (!isset($_POST['save_appointment_details']) || $_POST['save_appointment_details'] !== '1') {
        http_response_code(400);
        exit('A valid appointment update is required.');
    }
    foreach (['pickup_date', 'pickup_time', 'admin_notes'] as $field) {
        if (!isset($_POST[$field]) || !is_string($_POST[$field])) {
            http_response_code(400);
            exit('Enter valid pickup details and admin notes.');
        }
    }

    $postedPickupDate = trim($_POST['pickup_date']);
    $postedPickupTime = trim($_POST['pickup_time']);
    $postedAdminNotes = trim($_POST['admin_notes']);
    if (($postedPickupDate === '') !== ($postedPickupTime === '')) {
        $validationError = 'Enter both a pickup date and time, or leave both blank.';
    } elseif ($postedPickupDate !== '') {
        $parsedPickupDate = DateTimeImmutable::createFromFormat('!Y-m-d', $postedPickupDate, $manilaTimezone);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if (
            $parsedPickupDate === false
            || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
            || $parsedPickupDate->format('Y-m-d') !== $postedPickupDate
        ) {
            $validationError = 'Enter a valid pickup date.';
        }
    }
    if (
        $validationError === null
        && $postedPickupTime !== ''
        && !preg_match('/\A(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?\z/D', $postedPickupTime)
    ) {
        $validationError = 'Enter a valid pickup time.';
    }
    if ($validationError === null && auth_string_length($postedAdminNotes) > 5000) {
        $validationError = 'Admin notes must be 5,000 characters or fewer.';
    }

    if ($validationError === null) {
        $pickupDateValue = $postedPickupDate === '' ? null : $postedPickupDate;
        $pickupTimeValue = $postedPickupTime === ''
            ? null
            : (strlen($postedPickupTime) === 5 ? $postedPickupTime . ':00' : $postedPickupTime);
        $adminNotesValue = $postedAdminNotes === '' ? null : $postedAdminNotes;
        try {
            $statement = $conn->prepare(
                'UPDATE appointments
                 SET pickup_date = ?, pickup_time = ?, admin_notes = ?
                 WHERE appointment_id = ?'
            );
            $statement->bind_param('sssi', $pickupDateValue, $pickupTimeValue, $adminNotesValue, $appointmentId);
            $statement->execute();
            $appointmentExists = $statement->affected_rows === 1;
            $statement->close();
            if (!$appointmentExists) {
                $statement = $conn->prepare(
                    'SELECT 1
                     FROM appointments
                     WHERE appointment_id = ?
                     LIMIT 1'
                );
                $statement->bind_param('i', $appointmentId);
                $statement->execute();
                $appointmentExists = $statement->get_result()->num_rows === 1;
                $statement->close();
            }
            if (!$appointmentExists) {
                http_response_code(404);
                exit('Appointment not found.');
            }
            auth_set_flash('appointments_success', 'Pickup schedule and admin notes saved.');
            header('Location: ' . $detailsUrl, true, 303);
            exit;
        } catch (mysqli_sql_exception $exception) {
            error_log('Pawsome admin appointment details update failed: ' . $exception->getMessage());
            auth_set_flash('appointments_error', 'We could not save these appointment details right now. Please try again.');
            header('Location: ' . $detailsUrl, true, 303);
            exit;
        }
    }
}

try {
    $statement = $conn->prepare(
        'SELECT a.appointment_id, a.appointment_date, a.appointment_time,
                a.pickup_date, a.pickup_time, a.status,
                a.notes AS customer_notes, a.admin_notes,
                EXISTS (
                    SELECT 1
                    FROM grooming_records AS gr
                    WHERE gr.appointment_id = a.appointment_id
                ) AS has_grooming_record,
                u.first_name, u.last_name, u.email, u.phone,
                p.pet_id, p.pet_name, p.species, p.breed, p.gender, p.age, p.birthday,
                p.notes AS pet_notes, p.photo_path, p.vaccination_proof_path,
                p.color_markings, p.weight_kg, p.breed_size,
                s.service_name, s.duration, s.price AS current_service_price,
                a.service_price_at_booking, a.service_price_snapshot_saved
         FROM appointments AS a
         INNER JOIN users AS u ON u.user_id = a.user_id
         INNER JOIN pets AS p ON p.pet_id = a.pet_id AND p.user_id = a.user_id
         INNER JOIN services AS s ON s.service_id = a.service_id
         WHERE a.appointment_id = ?
         LIMIT 1'
    );
    $statement->bind_param('i', $appointmentId);
    $statement->execute();
    $appointment = $statement->get_result()->fetch_assoc();
    $statement->close();
} catch (mysqli_sql_exception $exception) {
    error_log('Pawsome admin appointment detail query failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('We could not load this appointment right now.');
}

if (!$appointment) {
    http_response_code(404);
    exit('Appointment not found.');
}

$successMessage = auth_take_flash('appointments_success');
$storedErrorMessage = auth_take_flash('appointments_error');
$errorMessage = $validationError ?? $storedErrorMessage;
$appointmentDateTime = new DateTimeImmutable(
    (string) $appointment['appointment_date'] . ' ' . (string) $appointment['appointment_time'],
    $manilaTimezone
);
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
$statusClass = $statusClasses[$status] ?? 'unknown';
$statusLabel = isset($statusClasses[$status]) ? $status : 'Unknown';
$priceDisplay = appointment_price_display($appointment);
$hasGroomingRecord = (int) $appointment['has_grooming_record'] === 1;
$availableActions = [];
foreach ($actionTransitions as $action => $transition) {
    if (
        in_array($status, $transition['from'], true)
        && (empty($transition['requires_no_grooming_record']) || !$hasGroomingRecord)
    ) {
        $availableActions[$action] = $transition;
    }
}
$petAge = $appointment['age'] === null
    ? 'Not provided'
    : ((int) $appointment['age'] === 0
        ? 'Under 1 year'
        : (int) $appointment['age'] . ' ' . ((int) $appointment['age'] === 1 ? 'year' : 'years'));
$petBirthday = !empty($appointment['birthday'])
    ? (new DateTimeImmutable((string) $appointment['birthday']))->format('M j, Y')
    : 'Not provided';
$petWeight = $appointment['weight_kg'] === null
    ? 'Not provided'
    : number_format((float) $appointment['weight_kg'], 2) . ' kg';
$petNotes = trim((string) ($appointment['pet_notes'] ?? ''));
$customerNotes = trim((string) ($appointment['customer_notes'] ?? ''));
$pickupDateValue = $validationError !== null
    ? (string) $postedPickupDate
    : (string) ($appointment['pickup_date'] ?? '');
$pickupTimeValue = $validationError !== null
    ? substr((string) $postedPickupTime, 0, 5)
    : substr((string) ($appointment['pickup_time'] ?? ''), 0, 5);
$adminNotes = $validationError !== null
    ? (string) $postedAdminNotes
    : (string) ($appointment['admin_notes'] ?? '');
$csrfToken = auth_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Manage a Pawsome customer appointment.">
    <meta name="theme-color" content="#fbf8ff">
    <title>Manage Appointment #<?= (int) $appointmentId ?> | Pawsome Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800;900&family=Nunito:wght@500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/shared/portal.css">
    <link rel="stylesheet" href="../assets/css/admin/dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin/appointments.css">
    <link rel="stylesheet" href="../assets/css/admin/appointment-details.css">
</head>
<body class="customer-app admin-app admin-appointment-details-page">
    <a class="skip-link" href="#admin-main">Skip to content</a>
    <div class="customer-layout admin-layout">
        <?php require __DIR__ . '/../includes/admin_sidebar.php'; ?>

        <div class="customer-main-column">
            <header class="customer-topbar admin-topbar">
                <div>
                    <span class="customer-topbar-label">Pawsome administration</span>
                    <p class="customer-topbar-greeting">Hello, <?= auth_escape($adminFirstName) ?></p>
                </div>
                <form method="post" action="../customer/logout.php">
                    <input type="hidden" name="csrf_token" value="<?= auth_escape($csrfToken) ?>">
                    <button class="button button-primary button-small admin-logout" type="submit">Log out</button>
                </form>
            </header>

            <main class="customer-main admin-main admin-appointment-detail-main" id="admin-main">
                <a class="admin-appointment-back" href="<?= auth_escape($returnUrl) ?>">&larr; Back to Appointments</a>

                <?php if ($successMessage !== null): ?>
                    <p class="admin-appointments-feedback is-success" role="status"><?= auth_escape($successMessage) ?></p>
                <?php endif; ?>
                <?php if ($errorMessage !== null): ?>
                    <p class="admin-appointments-feedback is-error" role="alert"><?= auth_escape($errorMessage) ?></p>
                <?php endif; ?>

                <section class="customer-page-heading admin-page-heading admin-appointment-detail-heading">
                    <div>
                        <span class="eyebrow">Appointment management</span>
                        <h1>Appointment <span>#<?= (int) $appointmentId ?></span></h1>
                        <p>Review the booking, customer, and pet details, then update its grooming status.</p>
                    </div>
                    <span class="customer-status customer-status--<?= auth_escape($statusClass) ?>">
                        <?= auth_escape($statusLabel) ?>
                    </span>
                </section>

                <section class="admin-panel admin-appointment-detail-card admin-appointment-status-card" aria-labelledby="appointment-actions-title">
                    <div class="admin-panel-heading">
                        <div>
                            <span class="eyebrow">Current status: <?= auth_escape($statusLabel) ?></span>
                            <h2 id="appointment-actions-title">Appointment Actions</h2>
                        </div>
                    </div>
                    <?php if ($availableActions): ?>
                        <div class="admin-appointment-detail-actions">
                            <?php foreach ($availableActions as $action => $transition): ?>
                                <form method="post" action="appointments.php" onsubmit="return confirm('<?= auth_escape($transition['confirmation_prompt']) ?>');">
                                    <input type="hidden" name="csrf_token" value="<?= auth_escape($csrfToken) ?>">
                                    <input type="hidden" name="appointment_id" value="<?= (int) $appointmentId ?>">
                                    <input type="hidden" name="appointment_action" value="<?= auth_escape($action) ?>">
                                    <input type="hidden" name="return_to" value="details">
                                    <input type="hidden" name="return_url" value="<?= auth_escape($returnUrl) ?>">
                                    <button class="admin-appointment-action is-<?= auth_escape($transition['button_class']) ?>" type="submit">
                                        <?= auth_escape($transition['label']) ?>
                                    </button>
                                </form>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="admin-appointment-no-actions">No further actions are available for this appointment.</p>
                    <?php endif; ?>
                    <?php if ($hasGroomingRecord && in_array($status, ['Pending', 'Confirmed', 'In Progress'], true)): ?>
                        <p class="admin-appointment-action-hint">Cancellation is unavailable because a grooming record is linked to this appointment.</p>
                    <?php endif; ?>
                    <?php if ($status === 'Ready for Pickup'): ?>
                        <p class="admin-appointment-completion-note">Mark Completed only after the pet has been picked up. Completing an appointment does not create a grooming record.</p>
                    <?php endif; ?>
                </section>

                <div class="admin-appointment-detail-grid">
                    <section class="admin-panel admin-appointment-detail-card" aria-labelledby="appointment-information-title">
                        <div class="admin-panel-heading">
                            <div>
                                <span class="eyebrow">Booking</span>
                                <h2 id="appointment-information-title">Booking</h2>
                            </div>
                        </div>
                        <dl class="admin-appointment-detail-list">
                            <div><dt>Appointment ID</dt><dd>#<?= (int) $appointmentId ?></dd></div>
                            <div><dt>Service</dt><dd><?= auth_escape((string) $appointment['service_name']) ?></dd></div>
                            <div>
                                <dt>Service price</dt>
                                <dd>
                                    <?= auth_escape($priceDisplay['formatted']) ?>
                                    <span class="admin-table-muted"><?= auth_escape($priceDisplay['source']) ?></span>
                                </dd>
                            </div>
                            <div><dt>Date</dt><dd><?= auth_escape($appointmentDateTime->format('M j, Y')) ?></dd></div>
                            <div><dt>Time</dt><dd><?= auth_escape($appointmentDateTime->format('g:i A')) ?></dd></div>
                            <div><dt>Status</dt><dd><?= auth_escape($statusLabel) ?></dd></div>
                        </dl>
                    </section>

                    <section class="admin-panel admin-appointment-detail-card" aria-labelledby="pickup-notes-title">
                        <div class="admin-panel-heading">
                            <div>
                                <span class="eyebrow">Pickup details and internal notes</span>
                                <h2 id="pickup-notes-title">Pickup Schedule &amp; Admin Notes</h2>
                            </div>
                        </div>
                        <form class="admin-appointment-notes-form" method="post" action="<?= auth_escape($detailsUrl) ?>">
                            <input type="hidden" name="csrf_token" value="<?= auth_escape($csrfToken) ?>">
                            <input type="hidden" name="save_appointment_details" value="1">
                            <div class="admin-appointment-pickup-fields">
                                <div>
                                    <label for="pickup-date">Pickup date</label>
                                    <input id="pickup-date" name="pickup_date" type="date" value="<?= auth_escape($pickupDateValue) ?>">
                                </div>
                                <div>
                                    <label for="pickup-time">Pickup time</label>
                                    <input id="pickup-time" name="pickup_time" type="time" value="<?= auth_escape($pickupTimeValue) ?>">
                                </div>
                            </div>
                            <label for="admin-notes">Internal notes</label>
                            <textarea id="admin-notes" name="admin_notes" rows="4" maxlength="5000"><?= auth_escape($adminNotes) ?></textarea>
                            <button class="button button-primary button-small" type="submit">Save Pickup &amp; Notes</button>
                        </form>
                    </section>

                    <section class="admin-panel admin-appointment-detail-card" aria-labelledby="customer-information-title">
                        <div class="admin-panel-heading">
                            <div>
                                <span class="eyebrow">Pet parent</span>
                                <h2 id="customer-information-title">Customer Information</h2>
                            </div>
                        </div>
                        <dl class="admin-appointment-detail-list">
                            <div>
                                <dt>Name</dt>
                                <dd><?= auth_escape(trim((string) $appointment['first_name'] . ' ' . (string) $appointment['last_name'])) ?></dd>
                            </div>
                            <div><dt>Email</dt><dd><?= auth_escape((string) ($appointment['email'] ?? '') ?: 'Not provided') ?></dd></div>
                            <div><dt>Phone</dt><dd><?= auth_escape(trim((string) ($appointment['phone'] ?? '')) ?: 'Not provided') ?></dd></div>
                        </dl>
                    </section>

                    <section class="admin-panel admin-appointment-detail-card" aria-labelledby="booking-notes-title">
                        <div class="admin-panel-heading">
                            <div>
                                <span class="eyebrow">Original customer notes</span>
                                <h2 id="booking-notes-title">Booking Notes</h2>
                            </div>
                        </div>
                        <div class="admin-appointment-note-content">
                            <?= $customerNotes !== '' ? nl2br(auth_escape($customerNotes)) : 'No booking notes provided.' ?>
                        </div>
                    </section>
                </div>

                <section class="admin-panel admin-appointment-detail-card admin-appointment-pet-card" aria-labelledby="pet-information-title">
                    <div class="admin-panel-heading">
                        <div>
                            <span class="eyebrow">Pet profile</span>
                            <h2 id="pet-information-title">Pet Profile</h2>
                        </div>
                    </div>
                    <div class="admin-appointment-pet-content">
                        <?php if (!empty($appointment['photo_path'])): ?>
                            <img
                                class="admin-appointment-pet-photo"
                                src="../customer/pet-media.php?pet_id=<?= (int) $appointment['pet_id'] ?>&amp;type=photo"
                                alt="<?= auth_escape((string) $appointment['pet_name']) ?> the <?= auth_escape((string) $appointment['species']) ?>"
                            >
                        <?php else: ?>
                            <div class="admin-appointment-pet-photo admin-appointment-pet-placeholder" aria-hidden="true">🐾</div>
                        <?php endif; ?>
                        <div class="admin-appointment-pet-profile">
                            <h3><?= auth_escape((string) $appointment['pet_name']) ?></h3>
                            <dl class="admin-appointment-detail-list">
                                <div><dt>Species</dt><dd><?= auth_escape((string) $appointment['species']) ?></dd></div>
                                <div><dt>Breed</dt><dd><?= auth_escape(trim((string) ($appointment['breed'] ?? '')) ?: 'Not provided') ?></dd></div>
                                <div><dt>Age</dt><dd><?= auth_escape($petAge) ?></dd></div>
                                <div><dt>Gender</dt><dd><?= auth_escape((string) $appointment['gender']) ?></dd></div>
                                <div><dt>Birthday</dt><dd><?= auth_escape($petBirthday) ?></dd></div>
                                <div><dt>Weight</dt><dd><?= auth_escape($petWeight) ?></dd></div>
                                <div><dt>Breed size</dt><dd><?= auth_escape(trim((string) ($appointment['breed_size'] ?? '')) ?: 'Not provided') ?></dd></div>
                                <div><dt>Color / markings</dt><dd><?= auth_escape(trim((string) ($appointment['color_markings'] ?? '')) ?: 'Not provided') ?></dd></div>
                            </dl>
                            <?php if (!empty($appointment['vaccination_proof_path'])): ?>
                                <a class="admin-appointment-proof-link" href="../customer/pet-media.php?pet_id=<?= (int) $appointment['pet_id'] ?>&amp;type=vaccination" target="_blank" rel="noopener">
                                    View vaccination proof
                                </a>
                            <?php else: ?>
                                <p class="admin-appointment-vaccination-status">No vaccination information uploaded.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="admin-appointment-pet-notes">
                        <h3>Pet profile notes</h3>
                        <p><?= $petNotes !== '' ? nl2br(auth_escape($petNotes)) : 'No additional pet details provided.' ?></p>
                    </div>
                </section>
            </main>

            <footer class="customer-footer">
                <p>&copy; <?= date('Y') ?> Pawsome. Made with care for pets and their people.</p>
            </footer>
        </div>
    </div>
</body>
</html>
