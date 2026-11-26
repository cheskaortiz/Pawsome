<?php
require_once __DIR__ . '/../includes/auth.php';
auth_require_admin();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/appointment_price.php';
require_once __DIR__ . '/../includes/activity_log.php';
require_once __DIR__ . '/../includes/admin_notifications.php';

$adminFirstName = (string) ($_SESSION['first_name'] ?? 'admin');
$activeAdminPage = 'appointments';
$statusFilters = ['All', 'Pending', 'Confirmed', 'In Progress', 'Ready for Pickup', 'Completed', 'Cancelled', 'Rejected'];
$manilaTimezone = new DateTimeZone('Asia/Manila');
$manilaNow = new DateTimeImmutable('now', $manilaTimezone);
$todayManila = $manilaNow->format('Y-m-d');
$statusFilter = $_GET['status'] ?? 'All';
$searchTerm = $_GET['search'] ?? '';
$fromDate = $_GET['from_date'] ?? '';
$toDate = $_GET['to_date'] ?? '';
$sortOrder = $_GET['sort'] ?? 'newest';
$validDate = static function (string $date) use ($manilaTimezone): bool {
    if ($date === '') {
        return true;
    }

    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $manilaTimezone);
    $errors = DateTimeImmutable::getLastErrors();

    return $parsed !== false
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        && $parsed->format('Y-m-d') === $date;
};

if (
    !is_string($statusFilter)
    || !in_array($statusFilter, $statusFilters, true)
    || !is_string($searchTerm)
    || auth_string_length(trim($searchTerm)) > 100
    || !is_string($fromDate)
    || !is_string($toDate)
    || !$validDate($fromDate)
    || !$validDate($toDate)
    || ($fromDate !== '' && $toDate !== '' && $fromDate > $toDate)
    || !is_string($sortOrder)
    || !in_array($sortOrder, ['newest', 'oldest'], true)
) {
    http_response_code(400);
    exit('Choose valid appointment filters and try again.');
}

$searchTerm = trim($searchTerm);
$queryParameters = [];
if ($searchTerm !== '') {
    $queryParameters['search'] = $searchTerm;
}
if ($fromDate !== '') {
    $queryParameters['from_date'] = $fromDate;
}
if ($toDate !== '') {
    $queryParameters['to_date'] = $toDate;
}
if ($statusFilter !== 'All') {
    $queryParameters['status'] = $statusFilter;
}
if ($sortOrder !== 'newest') {
    $queryParameters['sort'] = $sortOrder;
}
$returnUrl = 'appointments.php';
if ($queryParameters) {
    $returnUrl .= '?' . http_build_query($queryParameters);
}
$isAppointmentFragment = ($_GET['fragment'] ?? null) === 'appointments';

$summary = null;
$summaryLoadError = false;
if (!$isAppointmentFragment) {
    try {
        $statement = $conn->prepare(
            'SELECT COUNT(*) AS total_appointments,
                    COUNT(CASE WHEN appointment_date = ? THEN 1 END) AS today_appointments,
                    COUNT(CASE WHEN status = \'Pending\' THEN 1 END) AS pending_appointments,
                    COUNT(CASE WHEN status = \'Confirmed\' THEN 1 END) AS confirmed_appointments,
                    COUNT(CASE WHEN status = \'Completed\' THEN 1 END) AS completed_appointments,
                    COUNT(CASE WHEN status = \'Cancelled\' THEN 1 END) AS cancelled_appointments,
                    COUNT(CASE WHEN status = \'Rejected\' THEN 1 END) AS rejected_appointments
             FROM appointments'
        );
        $statement->bind_param('s', $todayManila);
        $statement->execute();
        $summary = $statement->get_result()->fetch_assoc();
        $statement->close();
    } catch (mysqli_sql_exception $exception) {
        error_log('Pawsome admin appointment summary query failed: ' . $exception->getMessage());
        $summaryLoadError = true;
    }
}

$actionTransitions = require __DIR__ . '/../includes/admin_appointment_workflow.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!auth_csrf_is_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        exit('Your session expired. Please reload the page and try again.');
    }

    $appointmentId = filter_var(
        auth_post_string('appointment_id'),
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );
    $action = auth_post_string('appointment_action');
    if ($appointmentId === false || !isset($actionTransitions[$action])) {
        http_response_code(400);
        exit('A valid appointment action is required.');
    }

    $transition = $actionTransitions[$action];
    $returnToDetails = auth_post_string('return_to') === 'details';
    $detailsReturnUrl = auth_post_string('return_url');
    $transactionStarted = false;
    try {
        $conn->begin_transaction();
        $transactionStarted = true;
        $statusStatement = $conn->prepare(
            'SELECT status
             FROM appointments
             WHERE appointment_id = ?
             FOR UPDATE'
        );
        $statusStatement->bind_param('i', $appointmentId);
        $statusStatement->execute();
        $appointmentBefore = $statusStatement->get_result()->fetch_assoc();
        $statusStatement->close();

        $allowedStatuses = $transition['from'];
        $statusPlaceholders = implode(', ', array_fill(0, count($allowedStatuses), '?'));
        $groomingRecordGuard = !empty($transition['requires_no_grooming_record'])
            ? ' AND NOT EXISTS (
                    SELECT 1
                    FROM grooming_records AS gr
                    WHERE gr.appointment_id = a.appointment_id
                )'
            : '';
        $statement = $conn->prepare(
            'UPDATE appointments AS a
             SET a.status = ?
             WHERE a.appointment_id = ?
               AND a.status IN (' . $statusPlaceholders . ')'
               . $groomingRecordGuard
        );
        $parameters = array_merge([$transition['target'], $appointmentId], $allowedStatuses);
        $parameterTypes = 'si' . str_repeat('s', count($allowedStatuses));
        $bindArguments = [$parameterTypes];
        foreach (array_keys($parameters) as $parameterIndex) {
            $bindArguments[] = &$parameters[$parameterIndex];
        }
        call_user_func_array([$statement, 'bind_param'], $bindArguments);
        if ($appointmentBefore) {
            $statement->execute();
            $updated = $statement->affected_rows === 1;
        } else {
            $updated = false;
        }
        $statement->close();

        if ($updated) {
            $previousStatus = (string) $appointmentBefore['status'];
            activity_log_current_user(
                $conn,
                'appointment.status_changed',
                'Appointment #' . $appointmentId . ' status changed from ' . $previousStatus . ' to ' . $transition['target'] . '.',
                [
                    'appointment_id' => (int) $appointmentId,
                    'from_status' => $previousStatus,
                    'to_status' => $transition['target'],
                ]
            );
            $notificationType = $transition['target'] === 'Cancelled'
                ? 'appointment.cancelled'
                : 'appointment.status_changed';
            $notificationTitle = $transition['target'] === 'Cancelled'
                ? 'Appointment cancelled'
                : 'Appointment status updated';
            admin_notification_broadcast(
                $conn,
                $notificationType,
                $notificationTitle,
                'Appointment #' . $appointmentId . ' status changed from '
                    . $previousStatus . ' to ' . $transition['target'] . '.',
                'appointment-details.php?id=' . $appointmentId
            );
            $conn->commit();
            $transactionStarted = false;
            auth_set_flash('appointments_success', 'Appointment status updated to ' . $transition['target'] . '.');
        } else {
            $conn->rollback();
            $transactionStarted = false;
            $statement = $conn->prepare(
                'SELECT a.status, EXISTS (
                            SELECT 1
                            FROM grooming_records AS gr
                            WHERE gr.appointment_id = a.appointment_id
                        ) AS has_grooming_record
                 FROM appointments AS a
                 INNER JOIN services AS s ON s.service_id = a.service_id
                 WHERE a.appointment_id = ?
                 LIMIT 1'
            );
            $statement->bind_param('i', $appointmentId);
            $statement->execute();
            $appointment = $statement->get_result()->fetch_assoc();
            $statement->close();

            if (!$appointment) {
                $message = 'That appointment could not be found.';
            } elseif (
                !empty($transition['requires_no_grooming_record'])
                && (int) $appointment['has_grooming_record'] === 1
            ) {
                $message = 'This appointment cannot be cancelled because a grooming record is already linked to it.';
            } else {
                $message = 'The appointment status has changed or this action is not allowed for its current status.';
            }
            auth_set_flash('appointments_error', $message);
        }
    } catch (mysqli_sql_exception $exception) {
        if ($transactionStarted) {
            $conn->rollback();
        }
        error_log('Pawsome admin appointment status update failed: ' . $exception->getMessage());
        auth_set_flash('appointments_error', 'We could not update this appointment right now. Please try again.');
    }

    $redirectUrl = $returnToDetails
        ? 'appointment-details.php?id=' . (int) $appointmentId
            . ($detailsReturnUrl !== '' ? '&return=' . rawurlencode($detailsReturnUrl) : '')
        : $returnUrl;
    header('Location: ' . $redirectUrl, true, 303);
    exit;
}

$successMessage = auth_take_flash('appointments_success');
$errorMessage = auth_take_flash('appointments_error');
$appointments = [];
$loadError = false;

try {
    $sql =
        'SELECT a.appointment_id, a.appointment_date, a.appointment_time, a.status,
                a.notes, a.created_at,
                EXISTS (
                    SELECT 1
                    FROM grooming_records AS gr
                    WHERE gr.appointment_id = a.appointment_id
                ) AS has_grooming_record,
                u.first_name, u.last_name, u.email, u.phone,
                p.pet_name, p.species, p.breed, p.breed_size,
                s.service_name, s.duration, s.price AS current_service_price,
                a.service_price_at_booking, a.service_price_snapshot_saved
         FROM appointments AS a
         INNER JOIN users AS u ON u.user_id = a.user_id
         INNER JOIN pets AS p ON p.pet_id = a.pet_id AND p.user_id = a.user_id
         INNER JOIN services AS s ON s.service_id = a.service_id';
    $conditions = [];
    $parameters = [];
    $parameterTypes = '';

    if ($searchTerm !== '') {
        $conditions[] = "(CAST(a.appointment_id AS CHAR) LIKE ?
            OR CONCAT_WS(' ', u.first_name, u.last_name) LIKE ?
            OR p.pet_name LIKE ?
            OR s.service_name LIKE ?)";
        $searchPattern = '%' . $searchTerm . '%';
        array_push($parameters, $searchPattern, $searchPattern, $searchPattern, $searchPattern);
        $parameterTypes .= 'ssss';
    }
    if ($fromDate !== '') {
        $conditions[] = 'a.appointment_date >= ?';
        $parameters[] = $fromDate;
        $parameterTypes .= 's';
    }
    if ($toDate !== '') {
        $conditions[] = 'a.appointment_date <= ?';
        $parameters[] = $toDate;
        $parameterTypes .= 's';
    }
    if ($statusFilter !== 'All') {
        $conditions[] = 'a.status = ?';
        $parameters[] = $statusFilter;
        $parameterTypes .= 's';
    }
    if ($conditions) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }
    $sortDirection = $sortOrder === 'oldest' ? 'ASC' : 'DESC';
    $sql .= ' ORDER BY a.appointment_date ' . $sortDirection
        . ', a.appointment_time ' . $sortDirection
        . ', a.created_at ' . $sortDirection;

    $statement = $conn->prepare($sql);
    if ($parameters) {
        $bindArguments = [$parameterTypes];
        foreach (array_keys($parameters) as $parameterIndex) {
            $bindArguments[] = &$parameters[$parameterIndex];
        }
        call_user_func_array([$statement, 'bind_param'], $bindArguments);
    }
    $statement->execute();
    $appointments = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
} catch (mysqli_sql_exception $exception) {
    error_log('Pawsome admin appointments query failed: ' . $exception->getMessage());
    $loadError = true;
}

$statusClasses = [
    'Pending' => 'pending',
    'Confirmed' => 'confirmed',
    'In Progress' => 'in-progress',
    'Ready for Pickup' => 'ready-for-pickup',
    'Completed' => 'completed',
    'Cancelled' => 'cancelled',
    'Rejected' => 'rejected',
    'Unknown' => 'unknown',
];
$csrfToken = auth_csrf_token();
if ($isAppointmentFragment) {
    header('Content-Type: text/html; charset=UTF-8');
    require __DIR__ . '/../includes/admin_appointments_table.php';
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Review and manage Pawsome customer appointments.">
    <meta name="theme-color" content="#fbf8ff">
    <title>Appointments | Pawsome Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800;900&family=Nunito:wght@500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/shared/portal.css">
    <link rel="stylesheet" href="../assets/css/admin/dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin/appointments.css">
</head>
<body class="customer-app admin-app admin-appointments-page">
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

            <main class="customer-main admin-main admin-appointments-main" id="admin-main">
                <section class="customer-page-heading admin-page-heading">
                    <div>
                        <span class="eyebrow">Admin area</span>
                        <h1>Customer <span>Appointments</span></h1>
                        <p>Review booking details and update appointment status.</p>
                    </div>
                </section>

                <?php if ($successMessage !== null): ?>
                    <p class="admin-appointments-feedback is-success" role="status"><?= auth_escape($successMessage) ?></p>
                <?php endif; ?>
                <?php if ($errorMessage !== null): ?>
                    <p class="admin-appointments-feedback is-error" role="alert"><?= auth_escape($errorMessage) ?></p>
                <?php endif; ?>

                <?php
                $summaryCards = [
                    ['Total Appointments', 'total_appointments', 'All recorded bookings'],
                    ['Today', 'today_appointments', 'Scheduled for ' . $manilaNow->format('M j, Y') . ' · Asia/Manila'],
                    ['Pending', 'pending_appointments', 'Awaiting a decision'],
                    ['Confirmed', 'confirmed_appointments', 'Reserved appointments'],
                    ['Completed', 'completed_appointments', 'Finished appointments'],
                    ['Cancelled', 'cancelled_appointments', 'Cancelled bookings'],
                    ['Rejected', 'rejected_appointments', 'Declined booking requests'],
                ];
                ?>
                <section class="admin-overview-grid admin-appointment-summary-grid" aria-label="Appointment summary">
                    <?php foreach ($summaryCards as [$label, $key, $description]): ?>
                        <article class="admin-overview-card">
                            <span class="admin-overview-label"><?= auth_escape($label) ?></span>
                            <strong class="admin-overview-value">
                                <?= $summaryLoadError || $summary === null ? '—' : number_format((int) $summary[$key]) ?>
                            </strong>
                            <span class="admin-overview-description"><?= auth_escape($description) ?></span>
                        </article>
                    <?php endforeach; ?>
                </section>
                <?php if ($summaryLoadError): ?>
                    <p class="admin-alert" role="alert">We couldn't load the appointment summary. Please try again later.</p>
                <?php endif; ?>

                <section class="admin-panel admin-appointments-filter-panel" aria-labelledby="appointment-filters-title">
                    <div class="admin-panel-heading">
                        <div>
                            <span class="eyebrow">Find a booking</span>
                            <h2 id="appointment-filters-title">Search &amp; Filters</h2>
                        </div>
                    </div>
                    <form class="admin-appointment-filter-form" id="appointment-filter-form" method="get" action="appointments.php">
                        <div class="admin-appointment-filter-field admin-appointment-search-field">
                            <label for="appointment-search">Search</label>
                            <input
                                type="search"
                                id="appointment-search"
                                name="search"
                                value="<?= auth_escape($searchTerm) ?>"
                                maxlength="100"
                                placeholder="ID, customer, pet, or service"
                            >
                        </div>
                        <div class="admin-appointment-filter-field">
                            <label for="appointment-from-date">From Date</label>
                            <input
                                type="date"
                                id="appointment-from-date"
                                name="from_date"
                                value="<?= auth_escape($fromDate) ?>"
                            >
                        </div>
                        <div class="admin-appointment-filter-field">
                            <label for="appointment-to-date">To Date</label>
                            <input
                                type="date"
                                id="appointment-to-date"
                                name="to_date"
                                value="<?= auth_escape($toDate) ?>"
                            >
                        </div>
                        <div class="admin-appointment-filter-field">
                            <label for="appointment-status">Status</label>
                            <select id="appointment-status" name="status">
                                <option value="All"<?= $statusFilter === 'All' ? ' selected' : '' ?>>All Statuses</option>
                                <?php foreach (array_slice($statusFilters, 1) as $filter): ?>
                                    <option value="<?= auth_escape($filter) ?>"<?= $statusFilter === $filter ? ' selected' : '' ?>>
                                        <?= auth_escape($filter) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="admin-appointment-filter-field">
                            <label for="appointment-sort">Date Sorting</label>
                            <select id="appointment-sort" name="sort">
                                <option value="newest"<?= $sortOrder === 'newest' ? ' selected' : '' ?>>Newest First</option>
                                <option value="oldest"<?= $sortOrder === 'oldest' ? ' selected' : '' ?>>Oldest First</option>
                            </select>
                        </div>
                    </form>
                </section>
                <p class="admin-appointments-feedback is-error" id="appointment-filter-feedback" role="alert" hidden></p>

                <?php require __DIR__ . '/../includes/admin_appointments_table.php'; ?>
            </main>

            <dialog class="admin-appointment-dialog" id="appointment-details-dialog" aria-labelledby="appointment-details-title">
                <div class="admin-appointment-dialog-content">
                    <header class="admin-appointment-dialog-header">
                        <div>
                            <span class="eyebrow">Booking information</span>
                            <h2 id="appointment-details-title">Appointment Details</h2>
                            <p>Reference <span data-appointment-detail="appointmentId"></span></p>
                        </div>
                        <button class="admin-appointment-dialog-close" type="button" aria-label="Close appointment details">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </header>

                    <div class="admin-appointment-dialog-grid">
                        <section class="admin-appointment-dialog-section">
                            <h3>Appointment</h3>
                            <dl>
                                <div><dt>Date</dt><dd data-appointment-detail="date"></dd></div>
                                <div><dt>Time</dt><dd data-appointment-detail="time"></dd></div>
                                <div><dt>Status</dt><dd data-appointment-detail="status"></dd></div>
                                <div><dt>Service</dt><dd data-appointment-detail="service"></dd></div>
                                <div><dt>Service price</dt><dd data-appointment-detail="price"></dd></div>
                                <div><dt>Duration</dt><dd data-appointment-detail="duration"></dd></div>
                            </dl>
                        </section>
                        <section class="admin-appointment-dialog-section">
                            <h3>Customer &amp; Pet</h3>
                            <dl>
                                <div><dt>Customer</dt><dd data-appointment-detail="customer"></dd></div>
                                <div><dt>Email</dt><dd data-appointment-detail="email"></dd></div>
                                <div><dt>Phone</dt><dd data-appointment-detail="phone"></dd></div>
                                <div><dt>Pet</dt><dd data-appointment-detail="petName"></dd></div>
                                <div><dt>Pet details</dt><dd data-appointment-detail="pet"></dd></div>
                            </dl>
                        </section>
                        <section class="admin-appointment-dialog-section">
                            <h3>Booking Notes</h3>
                            <p class="admin-appointment-dialog-notes" data-appointment-detail="notes"></p>
                        </section>
                        <section class="admin-appointment-dialog-section">
                            <h3>Record</h3>
                            <dl>
                                <div><dt>Grooming record</dt><dd data-appointment-detail="groomingRecord"></dd></div>
                                <div><dt>Requested</dt><dd data-appointment-detail="requested"></dd></div>
                            </dl>
                        </section>
                    </div>
                </div>
            </dialog>

            <footer class="customer-footer">
                <p>&copy; <?= date('Y') ?> Pawsome. Made with care for pets and their people.</p>
            </footer>
        </div>
    </div>
    <script>
        (() => {
            const filterForm = document.getElementById('appointment-filter-form');
            if (!filterForm) {
                return;
            }

            const appointmentDialog = document.getElementById('appointment-details-dialog');
            const dialogCloseButton = appointmentDialog.querySelector('.admin-appointment-dialog-close');
            let dialogTrigger = null;
            document.addEventListener('click', (event) => {
                if (!(event.target instanceof Element)) {
                    return;
                }

                const trigger = event.target.closest('[data-appointment-details]');
                if (!trigger) {
                    return;
                }

                dialogTrigger = trigger;
                for (const field of appointmentDialog.querySelectorAll('[data-appointment-detail]')) {
                    const key = field.dataset.appointmentDetail;
                    field.textContent = trigger.dataset[key] || (key === 'email' ? 'Not provided' : '—');
                }
                appointmentDialog.showModal();
            });
            dialogCloseButton.addEventListener('click', () => appointmentDialog.close());
            appointmentDialog.addEventListener('click', (event) => {
                if (event.target === appointmentDialog) {
                    appointmentDialog.close();
                }
            });
            appointmentDialog.addEventListener('close', () => {
                if (dialogTrigger && dialogTrigger.isConnected) {
                    dialogTrigger.focus();
                }
            });

            let searchTimer;
            const searchField = filterForm.querySelector('input[name="search"]');

            let resultsPanel = document.querySelector('.admin-appointments-panel');
            const filterFeedback = document.getElementById('appointment-filter-feedback');
            let activeRequest = null;

            const refreshAppointments = async () => {
                if (activeRequest) {
                    activeRequest.abort();
                }

                const controller = new AbortController();
                activeRequest = controller;
                const requestUrl = new URL(filterForm.action, window.location.href);
                requestUrl.search = new URLSearchParams(new FormData(filterForm)).toString();
                requestUrl.searchParams.set('fragment', 'appointments');
                resultsPanel.setAttribute('aria-busy', 'true');
                filterFeedback.hidden = true;

                try {
                    const response = await fetch(requestUrl, {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        signal: controller.signal,
                        cache: 'no-store'
                    });
                    if (!response.ok) {
                        throw new Error('Appointment refresh failed.');
                    }

                    const fragment = await response.text();
                    const documentFragment = new DOMParser().parseFromString(fragment, 'text/html');
                    const nextResultsPanel = documentFragment.querySelector('.admin-appointments-panel');
                    if (!nextResultsPanel) {
                        throw new Error('Appointment results were not returned.');
                    }

                    const scrollX = window.scrollX;
                    const scrollY = window.scrollY;
                    resultsPanel.replaceWith(nextResultsPanel);
                    resultsPanel = nextResultsPanel;
                    const displayUrl = new URL(requestUrl);
                    displayUrl.searchParams.delete('fragment');
                    history.replaceState(null, '', displayUrl.pathname + displayUrl.search + displayUrl.hash);
                    window.scrollTo(scrollX, scrollY);
                } catch (error) {
                    if (error.name !== 'AbortError') {
                        filterFeedback.textContent = 'Appointments could not be refreshed. Please try again.';
                        filterFeedback.hidden = false;
                    }
                } finally {
                    if (activeRequest === controller) {
                        activeRequest = null;
                        resultsPanel.removeAttribute('aria-busy');
                    }
                }
            };

            filterForm.addEventListener('change', (event) => {
                if (event.target.name === 'search') {
                    return;
                }
                window.clearTimeout(searchTimer);
                refreshAppointments();
            });

            searchField.addEventListener('input', () => {
                window.clearTimeout(searchTimer);
                searchTimer = window.setTimeout(refreshAppointments, 300);
            });

            filterForm.addEventListener('submit', (event) => {
                event.preventDefault();
                window.clearTimeout(searchTimer);
                refreshAppointments();
            });
        })();
    </script>
</body>
</html>
