<?php
require_once __DIR__ . '/../includes/auth.php';
auth_require_customer();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/customer_booking.php';

$pageTitle = 'Choose a Date and Time';
$activeCustomerPage = 'appointments';
$customerId = (int) $_SESSION['user_id'];
$errorMessage = auth_take_flash('booking_slot_error') ?? '';
$availabilityError = false;

try {
    $selection = customer_booking_get_selection($conn, $customerId);
} catch (mysqli_sql_exception $exception) {
    error_log('Pawsome booking selection lookup failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('We could not load your booking selection right now.');
}

if ($selection === null) {
    auth_set_flash('booking_flow_error', 'Your pet or service selection is no longer available. Please choose them again.');
    header('Location: booking.php', true, 303);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!auth_csrf_is_valid($_POST['csrf_token'] ?? null)) {
        $errorMessage = 'Your session expired. Please reload and try again.';
    } else {
        $action = auth_post_string('booking_action');

        if ($action === 'select_date') {
            $date = auth_post_string('booking_date');
            if (!customer_booking_is_open_date($date)) {
                $errorMessage = 'Choose a date that has not passed and is open for bookings.';
            } else {
                $_SESSION['booking_date'] = $date;
                unset($_SESSION['booking_time'], $_SESSION['booking_submission_token']);
                header('Location: booking-schedule.php?month=' . rawurlencode(substr($date, 0, 7)), true, 303);
                exit;
            }
        } elseif ($action === 'select_time') {
            $date = auth_post_string('booking_date');
            $time = auth_post_string('booking_time');
            $selectedDate = $_SESSION['booking_date'] ?? null;

            if (
                !is_string($selectedDate)
                || $selectedDate !== $date
                || !customer_booking_is_open_date($date)
                || !in_array($time, customer_booking_slot_times(), true)
            ) {
                $errorMessage = 'Choose an available appointment date and time.';
            } else {
                try {
                    $options = customer_booking_get_slot_options($conn, $date, (int) $selection['duration']);
                    $slotAvailable = false;
                    foreach ($options as $option) {
                        if ($option['time'] === $time && $option['available']) {
                            $slotAvailable = true;
                            break;
                        }
                    }

                    if (!$slotAvailable) {
                        $errorMessage = 'That time is no longer available. Please choose another slot.';
                        unset($_SESSION['booking_time'], $_SESSION['booking_submission_token']);
                    } else {
                        $_SESSION['booking_time'] = $time;
                        header('Location: booking-customer-info.php', true, 303);
                        exit;
                    }
                } catch (mysqli_sql_exception $exception) {
                    error_log('Pawsome appointment availability query failed: ' . $exception->getMessage());
                    $errorMessage = 'We could not check that time right now. Please try again.';
                }
            }
        } else {
            $errorMessage = 'Choose an appointment date and time to continue.';
        }
    }
}

$timezone = customer_booking_timezone();
$today = new DateTimeImmutable('today', $timezone);
$month = isset($_GET['month']) && is_string($_GET['month']) ? $_GET['month'] : $today->format('Y-m');
$monthDate = null;
if (preg_match('/\A\d{4}-\d{2}\z/', $month)) {
    $monthDate = DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01', $timezone);
    $monthErrors = DateTimeImmutable::getLastErrors();
    if (
        $monthDate === false
        || ($monthErrors !== false && ($monthErrors['warning_count'] > 0 || $monthErrors['error_count'] > 0))
        || $monthDate->format('Y-m') !== $month
    ) {
        $monthDate = null;
    }
}
if ($monthDate === null) {
    $monthDate = $today->modify('first day of this month');
}

$selectedDate = $_SESSION['booking_date'] ?? null;
if (!is_string($selectedDate) || !customer_booking_is_open_date($selectedDate)) {
    unset($_SESSION['booking_date'], $_SESSION['booking_time'], $_SESSION['booking_submission_token']);
    $selectedDate = null;
}
$selectedTime = $_SESSION['booking_time'] ?? null;
if (!is_string($selectedTime) || !in_array($selectedTime, customer_booking_slot_times(), true)) {
    unset($_SESSION['booking_time']);
    $selectedTime = null;
}

$slotOptions = [];
if ($selectedDate !== null) {
    try {
        $slotOptions = customer_booking_get_slot_options($conn, $selectedDate, (int) $selection['duration']);
        if ($selectedTime !== null) {
            $selectedTimeAvailable = false;
            foreach ($slotOptions as $option) {
                if ($option['time'] === $selectedTime && $option['available']) {
                    $selectedTimeAvailable = true;
                    break;
                }
            }
            if (!$selectedTimeAvailable) {
                unset($_SESSION['booking_time'], $_SESSION['booking_submission_token']);
                $selectedTime = null;
                $errorMessage = 'Your previously selected time is no longer available. Choose another slot.';
            }
        }
    } catch (mysqli_sql_exception $exception) {
        error_log('Pawsome appointment slot list query failed: ' . $exception->getMessage());
        $availabilityError = true;
    }
}

$monthStart = $monthDate->modify('first day of this month');
$daysInMonth = (int) $monthStart->format('t');
$firstWeekday = (int) $monthStart->format('N');
$previousMonth = $monthStart->format('Y-m') === $today->format('Y-m')
    ? null
    : $monthStart->modify('-1 month')->format('Y-m');
$nextMonth = $monthStart->modify('+1 month')->format('Y-m');
$timePeriods = ['Morning' => [], 'Afternoon' => []];
foreach ($slotOptions as $option) {
    $timePeriods[$option['period']][] = $option;
}

require __DIR__ . '/../includes/customer_header.php';
?>
<section class="customer-page-heading">
    <span class="eyebrow">Step 3 · <?= auth_escape(customer_booking_service_label($selection)) ?> for <?= auth_escape((string) $selection['pet_name']) ?></span>
    <h1>Choose a Date <span>&amp; Time</span></h1>
    <p>Appointments are available Monday through Saturday, 9:00 AM–5:00 PM, with a lunch break from noon to 1:00 PM.</p>
</section>

<?php if ($errorMessage !== ''): ?>
    <p class="customer-alert" role="alert"><?= auth_escape($errorMessage) ?></p>
<?php endif; ?>
<?php if ($availabilityError): ?>
    <p class="customer-alert" role="alert">We couldn't check appointment availability right now. Please try again later.</p>
<?php endif; ?>

<div class="booking-schedule-layout">
<section class="booking-step-section" aria-labelledby="booking-calendar-title">
    <div class="customer-section-heading">
        <h2 id="booking-calendar-title">Select an appointment date</h2>
    </div>
    <div class="booking-calendar">
        <div class="booking-calendar-header">
            <?php if ($previousMonth !== null): ?>
                <a class="booking-calendar-nav" href="booking-schedule.php?month=<?= auth_escape($previousMonth) ?>" aria-label="Previous month">‹</a>
            <?php else: ?>
                <span class="booking-calendar-nav is-disabled" aria-hidden="true">‹</span>
            <?php endif; ?>
            <h3><?= auth_escape($monthStart->format('F Y')) ?></h3>
            <a class="booking-calendar-nav" href="booking-schedule.php?month=<?= auth_escape($nextMonth) ?>" aria-label="Next month">›</a>
        </div>

        <div class="booking-calendar-grid" aria-hidden="true">
            <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $weekday): ?>
                <span class="booking-calendar-weekday"><?= auth_escape($weekday) ?></span>
            <?php endforeach; ?>
        </div>
        <form method="post" action="booking-schedule.php?month=<?= auth_escape($monthStart->format('Y-m')) ?>" class="booking-calendar-grid">
            <input type="hidden" name="csrf_token" value="<?= auth_escape(auth_csrf_token()) ?>">
            <input type="hidden" name="booking_action" value="select_date">
            <?php for ($blankDay = 1; $blankDay < $firstWeekday; $blankDay++): ?>
                <span class="booking-calendar-blank" aria-hidden="true"></span>
            <?php endfor; ?>
            <?php for ($day = 1; $day <= $daysInMonth; $day++): ?>
                <?php
                $dayDate = $monthStart->setDate(
                    (int) $monthStart->format('Y'),
                    (int) $monthStart->format('n'),
                    $day
                );
                $dayValue = $dayDate->format('Y-m-d');
                $dateIsOpen = customer_booking_is_open_date($dayValue);
                $isSelectedDate = $dayValue === $selectedDate;
                ?>
                <?php if ($dateIsOpen): ?>
                    <button
                        class="booking-calendar-day<?= $isSelectedDate ? ' is-selected' : '' ?>"
                        type="submit"
                        name="booking_date"
                        value="<?= auth_escape($dayValue) ?>"
                        aria-pressed="<?= $isSelectedDate ? 'true' : 'false' ?>"
                    >
                        <?= $day ?>
                    </button>
                <?php else: ?>
                    <span class="booking-calendar-day is-unavailable" aria-label="<?= auth_escape($dayDate->format('F j')) ?> unavailable"><?= $day ?></span>
                <?php endif; ?>
            <?php endfor; ?>
        </form>
        <p class="customer-muted booking-calendar-note">Sundays and past dates are unavailable. Select a date to view live time availability.</p>
    </div>
</section>

<?php if ($selectedDate !== null): ?>
    <section class="booking-step-section" aria-labelledby="booking-times-title">
        <div class="customer-section-heading">
            <span class="eyebrow"><?= auth_escape((new DateTimeImmutable($selectedDate, $timezone))->format('l, F j, Y')) ?></span>
            <h2 id="booking-times-title">Available appointment times</h2>
        </div>
        <?php if (!$availabilityError): ?>
            <?php
            $availableSlotCount = 0;
            foreach ($slotOptions as $option) {
                if ($option['available']) {
                    $availableSlotCount++;
                }
            }
            ?>
            <?php if ($availableSlotCount === 0): ?>
                <p class="customer-info" role="status">No start times are available for this date and service duration.</p>
                <?php foreach ($timePeriods as $period => $options): ?>
                    <section class="booking-time-period" aria-label="<?= auth_escape($period) ?> time slots">
                        <h3><?= auth_escape($period) ?></h3>
                        <div class="booking-time-grid">
                            <?php foreach ($options as $option): ?>
                                <span class="booking-time-slot is-unavailable" title="<?= auth_escape($option['reason']) ?>">
                                    <strong><?= auth_escape($option['label']) ?></strong>
                                    <small><?= auth_escape($option['reason']) ?></small>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endforeach; ?>
            <?php else: ?>
                <form method="post" action="booking-schedule.php?month=<?= auth_escape($monthStart->format('Y-m')) ?>">
                    <input type="hidden" name="csrf_token" value="<?= auth_escape(auth_csrf_token()) ?>">
                    <input type="hidden" name="booking_action" value="select_time">
                    <input type="hidden" name="booking_date" value="<?= auth_escape($selectedDate) ?>">
                    <?php foreach ($timePeriods as $period => $options): ?>
                        <section class="booking-time-period" aria-label="<?= auth_escape($period) ?> time slots">
                            <h3><?= auth_escape($period) ?></h3>
                            <div class="booking-time-grid">
                                <?php foreach ($options as $option): ?>
                                    <?php if ($option['available']): ?>
                                        <label class="booking-time-option">
                                            <input
                                                type="radio"
                                                name="booking_time"
                                                value="<?= auth_escape($option['time']) ?>"
                                                <?= $selectedTime === $option['time'] ? 'checked' : '' ?>
                                                required
                                            >
                                            <span class="booking-time-slot<?= $selectedTime === $option['time'] ? ' is-selected' : '' ?>">
                                                <strong><?= auth_escape($option['label']) ?></strong>
                                                <small><?= $selectedTime === $option['time'] ? 'Selected' : 'Available' ?></small>
                                            </span>
                                        </label>
                                    <?php else: ?>
                                        <span class="booking-time-slot is-unavailable" title="<?= auth_escape($option['reason']) ?>">
                                            <strong><?= auth_escape($option['label']) ?></strong>
                                            <small><?= auth_escape($option['reason']) ?></small>
                                        </span>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endforeach; ?>
                    <div class="booking-step-actions">
                        <button class="button button-primary" type="submit">Continue <span aria-hidden="true">→</span></button>
                    </div>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </section>
<?php else: ?>
    <section class="booking-step-section" aria-labelledby="booking-times-title">
        <div class="customer-section-heading">
            <h2 id="booking-times-title">Available appointment times</h2>
        </div>
        <p class="customer-info" role="status">Select an open date on the calendar to see available times for this service.</p>
    </section>
<?php endif; ?>
</div>

<div class="booking-step-actions">
    <a class="button pet-cancel-button" href="booking.php?step=2">← Change pet or service</a>
</div>
<?php require __DIR__ . '/../includes/customer_footer.php'; ?>
