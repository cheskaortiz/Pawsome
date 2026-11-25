<?php
require_once __DIR__ . '/../includes/auth.php';
auth_require_customer();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/customer_booking.php';

$pageTitle = 'Booking Information';
$activeCustomerPage = 'appointments';
$customerId = (int) $_SESSION['user_id'];
$errorMessage = '';

try {
    $selection = customer_booking_get_selection($conn, $customerId);
    $customer = customer_booking_get_customer($conn, $customerId);
} catch (mysqli_sql_exception $exception) {
    error_log('Pawsome booking information lookup failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('We could not load your booking information right now.');
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
    error_log('Pawsome booking information availability query failed: ' . $exception->getMessage());
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

$bookingNotes = isset($_SESSION['booking_notes']) && is_string($_SESSION['booking_notes'])
    ? $_SESSION['booking_notes']
    : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bookingNotes = trim(auth_post_string('booking_notes'));

    if (!auth_csrf_is_valid($_POST['csrf_token'] ?? null)) {
        $errorMessage = 'Your session expired. Please reload and try again.';
    } elseif (auth_string_length($bookingNotes) > 2000) {
        $errorMessage = 'Booking notes must be 2,000 characters or fewer.';
    } else {
        $_SESSION['booking_notes'] = $bookingNotes;
        header('Location: booking-review.php', true, 303);
        exit;
    }
}

require __DIR__ . '/../includes/customer_header.php';
?>
<section class="customer-page-heading">
    <span class="eyebrow">Step 4 · Customer information</span>
    <h1>Your Booking <span>Details</span></h1>
    <p>Review the information on your account and add any optional notes for the grooming team.</p>
</section>

<?php if ($errorMessage !== ''): ?>
    <p class="customer-alert" role="alert"><?= auth_escape($errorMessage) ?></p>
<?php endif; ?>

<section class="booking-step-section" aria-labelledby="booking-customer-title">
    <div class="customer-section-heading">
        <h2 id="booking-customer-title">Customer information</h2>
    </div>
    <dl class="customer-detail-list booking-readonly-details">
        <div><dt>First name</dt><dd><?= auth_escape((string) $customer['first_name']) ?></dd></div>
        <div><dt>Last name</dt><dd><?= auth_escape((string) $customer['last_name']) ?></dd></div>
        <div><dt>Email address</dt><dd><?= auth_escape((string) $customer['email']) ?></dd></div>
        <div>
            <dt>Contact number</dt>
            <dd><?= !empty($customer['phone']) ? auth_escape((string) $customer['phone']) : 'Not provided' ?></dd>
        </div>
    </dl>
</section>

<section class="booking-step-section" aria-labelledby="booking-pet-summary-title">
    <div class="customer-section-heading">
        <h2 id="booking-pet-summary-title">Selected pet</h2>
    </div>
    <article class="booking-pet-summary">
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
            <p><?= auth_escape((string) $selection['species']) ?><?= !empty($selection['breed']) ? ' · ' . auth_escape((string) $selection['breed']) : '' ?></p>
        </div>
    </article>
</section>

<section class="booking-step-section" aria-labelledby="booking-notes-title">
    <div class="customer-section-heading">
        <h2 id="booking-notes-title">Booking notes <span class="customer-muted">(optional)</span></h2>
        <p>Share special requests, grooming preferences, or anything helpful about your pet's behavior.</p>
    </div>
    <form method="post" action="booking-customer-info.php">
        <input type="hidden" name="csrf_token" value="<?= auth_escape(auth_csrf_token()) ?>">
        <label class="booking-notes-label" for="booking_notes">Notes for the grooming team</label>
        <textarea
            class="booking-notes-field"
            id="booking_notes"
            name="booking_notes"
            rows="5"
            maxlength="2000"
            aria-describedby="booking-notes-help"
        ><?= auth_escape($bookingNotes) ?></textarea>
        <p class="customer-muted" id="booking-notes-help">Up to 2,000 characters. Do not include payment details or passwords.</p>
        <div class="booking-step-actions">
            <a class="button pet-cancel-button" href="booking-schedule.php">← Back to date and time</a>
            <button class="button button-primary" type="submit">Continue to review <span aria-hidden="true">→</span></button>
        </div>
    </form>
</section>
<?php require __DIR__ . '/../includes/customer_footer.php'; ?>
