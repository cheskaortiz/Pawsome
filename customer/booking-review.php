<?php
require_once __DIR__ . '/../includes/auth.php';
auth_require_customer();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/customer_booking.php';

$pageTitle = 'Review Your Booking';
$activeCustomerPage = 'appointments';
$customerId = (int) $_SESSION['user_id'];
$errorMessage = '';
$conflictingSlot = false;

try {
    $selection = customer_booking_get_selection($conn, $customerId);
    $customer = customer_booking_get_customer($conn, $customerId);
} catch (mysqli_sql_exception $exception) {
    error_log('Pawsome booking review lookup failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('We could not load your booking review right now.');
}

if ($selection === null) {
    auth_set_flash('booking_flow_error', 'Your pet or service selection is no longer available. Please choose them again.');
    header('Location: booking.php', true, 303);
    exit;
}
if ($customer === null) {
    customer_booking_clear_selection();
    http_response_code(404);
    exit('Customer account not found.');
}

$bookingDate = $_SESSION['booking_date'] ?? null;
$bookingTime = $_SESSION['booking_time'] ?? null;
if (
    !is_string($bookingDate)
    || !customer_booking_is_open_date($bookingDate)
    || !is_string($bookingTime)
    || !in_array($bookingTime, customer_booking_slot_times(), true)
) {
    header('Location: booking-schedule.php', true, 303);
    exit;
}

try {
    $slotOptions = customer_booking_get_slot_options($conn, $bookingDate, (int) $selection['duration']);
} catch (mysqli_sql_exception $exception) {
    error_log('Pawsome booking review availability query failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('We could not confirm the selected time right now.');
}

$selectedSlotAvailable = false;
foreach ($slotOptions as $option) {
    if ($option['time'] === $bookingTime && $option['available']) {
        $selectedSlotAvailable = true;
        break;
    }
}
if (!$selectedSlotAvailable) {
    unset($_SESSION['booking_time'], $_SESSION['booking_submission_token']);
    auth_set_flash('booking_slot_error', 'That time is no longer available. Please choose another slot.');
    header('Location: booking-schedule.php?month=' . rawurlencode(substr($bookingDate, 0, 7)), true, 303);
    exit;
}

try {
    $submissionToken = $_SESSION['booking_submission_token'] ?? null;
    if (!is_string($submissionToken) || strlen($submissionToken) !== 64) {
        $submissionToken = bin2hex(random_bytes(32));
        $_SESSION['booking_submission_token'] = $submissionToken;
    }
} catch (Random\RandomException $exception) {
    error_log('Pawsome booking submission token generation failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('We could not prepare your booking confirmation right now.');
}

$bookingNotes = isset($_SESSION['booking_notes']) && is_string($_SESSION['booking_notes'])
    ? $_SESSION['booking_notes']
    : '';
$customerName = trim((string) $customer['first_name'] . ' ' . (string) $customer['last_name']);
$profileComplete = trim((string) $customer['first_name']) !== ''
    && trim((string) $customer['last_name']) !== ''
    && trim((string) $customer['email']) !== ''
    && trim((string) ($customer['phone'] ?? '')) !== ''
    && filter_var((string) $customer['email'], FILTER_VALIDATE_EMAIL) !== false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = auth_post_string('booking_submission_token');
    if (!auth_csrf_is_valid($_POST['csrf_token'] ?? null)) {
        $errorMessage = 'Your session expired. Please reload and try again.';
    } elseif (!hash_equals($submissionToken, $postedToken)) {
        $errorMessage = 'This confirmation form has already been used or expired. Review your booking again.';
    } elseif (!$profileComplete) {
        $errorMessage = 'Your account is missing required contact information. Please update your account or contact Pawsome before booking.';
    } elseif (auth_string_length($bookingNotes) > 2000) {
        $errorMessage = 'Booking notes must be 2,000 characters or fewer.';
    } else {
        $lockName = 'pawsome_global_appointment_capacity';
        $lockAcquired = false;
        $transactionStarted = false;
        $bookingCreated = false;
        $selectionInvalidated = false;
        $appointmentId = 0;

        try {
            $lockStatement = $conn->prepare('SELECT GET_LOCK(?, 10) AS lock_acquired');
            $lockStatement->bind_param('s', $lockName);
            $lockStatement->execute();
            $lockResult = $lockStatement->get_result()->fetch_assoc();
            $lockStatement->close();

            if (!$lockResult || (int) $lockResult['lock_acquired'] !== 1) {
                $errorMessage = 'Another booking is being confirmed. Please try again in a moment.';
            } else {
                $lockAcquired = true;
                $conn->begin_transaction();
                $transactionStarted = true;

                $petStatement = $conn->prepare(
                    'SELECT pet_id, species, breed_size
                     FROM pets
                     WHERE pet_id = ? AND user_id = ?
                     FOR UPDATE'
                );
                $petId = (int) $selection['pet_id'];
                $petStatement->bind_param('ii', $petId, $customerId);
                $petStatement->execute();
                $petExists = $petStatement->get_result()->fetch_assoc();
                $petStatement->close();

                $petSpecies = (string) ($petExists['species'] ?? '');
                $petSize = (string) ($petExists['breed_size'] ?? '');
                $currentService = null;
                if (
                    $petExists
                    && ($petSpecies !== 'Dog' || in_array($petSize, ['Small', 'Medium', 'Large', 'Giant'], true))
                ) {
                    $serviceStatement = $conn->prepare(
                        "SELECT service_id, species, pet_size, price, duration
                         FROM services
                         WHERE service_id = ?
                           AND species = ?
                           AND (pet_size = 'Any' OR pet_size = ?)
                           AND category IN (
                               'Basic Groom',
                               'Premium Groom',
                               'A La Carte Services (Flat Rate)',
                               'A La Carte Services (Size-Dependent)'
                           )
                           AND status = 'Available'
                           AND duration > 0
                         FOR UPDATE"
                    );
                    $serviceId = (int) $selection['service_id'];
                    $serviceStatement->bind_param('iss', $serviceId, $petSpecies, $petSize);
                    $serviceStatement->execute();
                    $currentService = $serviceStatement->get_result()->fetch_assoc();
                    $serviceStatement->close();
                }

                if (!$petExists || !$currentService) {
                    $conn->rollback();
                    $transactionStarted = false;
                    $errorMessage = 'The selected pet or service is no longer available. Please restart your booking.';
                    customer_booking_clear_selection();
                    $selectionInvalidated = true;
                } else {
                    $currentSlots = customer_booking_get_slot_options(
                        $conn,
                        $bookingDate,
                        (int) $currentService['duration'],
                        true
                    );
                    $slotStillAvailable = false;
                    foreach ($currentSlots as $option) {
                        if ($option['time'] === $bookingTime && $option['available']) {
                            $slotStillAvailable = true;
                            break;
                        }
                    }

                    if (!$slotStillAvailable) {
                        $conn->rollback();
                        $transactionStarted = false;
                        $conflictingSlot = true;
                        $errorMessage = 'That time was just booked. Please choose another available slot.';
                    } else {
                        $insert = $conn->prepare(
                            "INSERT INTO appointments
                                (user_id, pet_id, service_id, appointment_date, appointment_time, status, notes)
                             VALUES (?, ?, ?, ?, ?, 'Pending', ?)"
                        );
                        $appointmentTime = $bookingTime;
                        $insert->bind_param(
                            'iiisss',
                            $customerId,
                            $petId,
                            $serviceId,
                            $bookingDate,
                            $appointmentTime,
                            $bookingNotes
                        );
                        $insert->execute();
                        $appointmentId = (int) $conn->insert_id;
                        $insert->close();
                        $conn->commit();
                        $transactionStarted = false;
                        $bookingCreated = true;
                    }
                }
            }
        } catch (mysqli_sql_exception $exception) {
            if ($transactionStarted) {
                try {
                    $conn->rollback();
                } catch (mysqli_sql_exception $rollbackException) {
                    error_log('Pawsome booking rollback failed: ' . $rollbackException->getMessage());
                }
                $transactionStarted = false;
            }
            error_log('Pawsome appointment confirmation failed: ' . $exception->getMessage());
            $errorMessage = 'We could not save your appointment right now. Please try again.';
        } catch (InvalidArgumentException $exception) {
            if ($transactionStarted) {
                try {
                    $conn->rollback();
                } catch (mysqli_sql_exception $rollbackException) {
                    error_log('Pawsome booking rollback failed: ' . $rollbackException->getMessage());
                }
                $transactionStarted = false;
            }
            $conflictingSlot = true;
            $errorMessage = 'The selected date or service duration is no longer valid. Choose another appointment time.';
        } finally {
            if ($lockAcquired) {
                try {
                    $releaseStatement = $conn->prepare('SELECT RELEASE_LOCK(?) AS released');
                    $releaseStatement->bind_param('s', $lockName);
                    $releaseStatement->execute();
                    $released = $releaseStatement->get_result()->fetch_assoc();
                    $releaseStatement->close();
                    if (!$released || (int) $released['released'] !== 1) {
                        error_log('Pawsome booking capacity lock was not released explicitly.');
                    }
                } catch (mysqli_sql_exception $releaseException) {
                    error_log('Pawsome booking capacity lock release failed: ' . $releaseException->getMessage());
                }
            }
        }

        if ($selectionInvalidated) {
            auth_set_flash('booking_flow_error', $errorMessage);
            header('Location: booking.php', true, 303);
            exit;
        }

        if ($bookingCreated) {
            auth_set_flash(
                'appointment_success',
                'Appointment #' . $appointmentId . ' has been requested and is Pending confirmation.'
            );
            customer_booking_clear_selection();
            header('Location: appointments.php', true, 303);
            exit;
        }

        if ($conflictingSlot) {
            unset($_SESSION['booking_time'], $_SESSION['booking_submission_token']);
            auth_set_flash('booking_slot_error', $errorMessage);
            header('Location: booking-schedule.php?month=' . rawurlencode(substr($bookingDate, 0, 7)), true, 303);
            exit;
        }
    }
}

if (!isset($_SESSION['booking_submission_token']) || !is_string($_SESSION['booking_submission_token'])) {
    $_SESSION['booking_submission_token'] = $submissionToken;
}
$category = (string) $selection['category'];
$formattedBookingDate = (new DateTimeImmutable($bookingDate, customer_booking_timezone()))->format('l, F j, Y');
$formattedBookingTime = (new DateTimeImmutable($bookingDate . ' ' . $bookingTime, customer_booking_timezone()))->format('g:i A');

require __DIR__ . '/../includes/customer_header.php';
?>
<section class="customer-page-heading">
    <span class="eyebrow">Step 5 · Final check</span>
    <h1>Review Your <span>Booking</span></h1>
    <p>Check that everything is right before sending your appointment request.</p>
</section>

<?php if ($errorMessage !== ''): ?>
    <p class="customer-alert" role="alert"><?= auth_escape($errorMessage) ?></p>
<?php endif; ?>

<div class="booking-review-grid">
    <section class="booking-review-card" aria-labelledby="review-customer-title">
        <h2 id="review-customer-title">Customer information</h2>
        <dl class="customer-detail-list">
            <div><dt>Name</dt><dd><?= auth_escape($customerName) ?></dd></div>
            <div><dt>Email</dt><dd><?= auth_escape((string) $customer['email']) ?></dd></div>
            <div>
                <dt>Contact number</dt>
                <dd><?= !empty($customer['phone']) ? auth_escape((string) $customer['phone']) : 'Not provided' ?></dd>
            </div>
        </dl>
    </section>

    <section class="booking-review-card" aria-labelledby="review-pet-title">
        <h2 id="review-pet-title">Pet information</h2>
        <div class="booking-review-pet">
            <?php if (!empty($selection['photo_path'])): ?>
                <img
                    src="pet-media.php?pet_id=<?= (int) $selection['pet_id'] ?>&amp;type=photo"
                    alt=""
                    loading="lazy"
                >
            <?php else: ?>
                <span class="booking-pet-placeholder" aria-hidden="true">🐾</span>
            <?php endif; ?>
            <div>
                <h3><?= auth_escape((string) $selection['pet_name']) ?></h3>
                <p><?= auth_escape($selection['species'] === 'Other' ? 'Other Small Animal' : (string) $selection['species']) ?><?= !empty($selection['breed']) ? ' · ' . auth_escape((string) $selection['breed']) : '' ?></p>
            </div>
        </div>
    </section>

    <section class="booking-review-card booking-review-card-wide" aria-labelledby="review-booking-title">
        <h2 id="review-booking-title">Booking information</h2>
        <dl class="customer-detail-list">
            <div><dt>Category</dt><dd><?= auth_escape($category) ?></dd></div>
            <div><dt>Service</dt><dd><?= auth_escape((string) $selection['service_name']) ?></dd></div>
            <div><dt>Appointment date</dt><dd><?= auth_escape($formattedBookingDate) ?></dd></div>
            <div><dt>Appointment time</dt><dd><?= auth_escape($formattedBookingTime) ?></dd></div>
            <div><dt>Service duration</dt><dd><?= (int) $selection['duration'] ?> minutes</dd></div>
            <div><dt>Service price</dt><dd><?= auth_escape(grooming_format_service_price($selection)) ?></dd></div>
            <div><dt>Booking notes</dt><dd><?= $bookingNotes !== '' ? nl2br(auth_escape($bookingNotes)) : 'None' ?></dd></div>
        </dl>
        <p class="customer-muted customer-price-note">This request will be saved as Pending until Pawsome confirms it.</p>
    </section>
</div>

<form class="booking-confirm-form" method="post" action="booking-review.php">
    <input type="hidden" name="csrf_token" value="<?= auth_escape(auth_csrf_token()) ?>">
    <input type="hidden" name="booking_submission_token" value="<?= auth_escape($submissionToken) ?>">
    <div class="booking-step-actions">
        <a class="button pet-cancel-button" href="booking-customer-info.php">← Back to customer information</a>
        <?php if ($profileComplete): ?>
            <button class="button button-primary" type="submit">Book Appointment <span aria-hidden="true">→</span></button>
        <?php endif; ?>
    </div>
</form>
<?php require __DIR__ . '/../includes/customer_footer.php'; ?>
