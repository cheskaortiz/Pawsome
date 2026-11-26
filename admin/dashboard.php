<?php
require_once __DIR__ . '/../includes/auth.php';
auth_require_admin();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/appointment_price.php';

$adminFirstName = (string) ($_SESSION['first_name'] ?? 'admin');
$activeAdminPage = 'dashboard';
$manilaTimezone = new DateTimeZone('Asia/Manila');
$manilaNow = new DateTimeImmutable('now', $manilaTimezone);
$today = $manilaNow->setTime(0, 0);
$todayKey = $today->format('Y-m-d');
$tomorrowKey = $today->modify('+1 day')->format('Y-m-d');
$weekStart = $today->modify('monday this week');
$nextWeekStart = $weekStart->modify('+1 week');
$csrfToken = auth_csrf_token();
$actionTransitions = require __DIR__ . '/../includes/admin_appointment_workflow.php';
$appointmentActionSuccess = auth_take_flash('appointments_success');
$appointmentActionError = auth_take_flash('appointments_error');

$fetchRows = static function (string $sql, string $parameterTypes = '', array $parameters = []) use ($conn): array {
    $statement = $conn->prepare($sql);
    if ($parameters) {
        $bindArguments = [$parameterTypes];
        foreach (array_keys($parameters) as $parameterIndex) {
            $bindArguments[] = &$parameters[$parameterIndex];
        }
        call_user_func_array([$statement, 'bind_param'], $bindArguments);
    }
    $statement->execute();
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    return $rows;
};
$fetchOne = static function (string $sql, string $parameterTypes = '', array $parameters = []) use ($fetchRows): array {
    return $fetchRows($sql, $parameterTypes, $parameters)[0] ?? [];
};

$dashboardSummary = null;
$summaryLoadError = false;
try {
    $dashboardSummary = $fetchOne(
        "SELECT
            (SELECT COUNT(*) FROM appointments) AS total_appointments,
            (SELECT COUNT(*) FROM appointments WHERE status = 'Pending') AS pending_appointments,
            (SELECT COUNT(*) FROM users WHERE role = 'customer') AS total_customers,
            (SELECT COUNT(*) FROM pets) AS registered_pets,
            (SELECT COUNT(*) FROM appointments
             WHERE appointment_date = ?
               AND status NOT IN ('Cancelled', 'Rejected')) AS today_appointments",
        's',
        [$todayKey]
    );
} catch (mysqli_sql_exception $exception) {
    error_log('Pawsome admin dashboard summary query failed: ' . $exception->getMessage());
    $summaryLoadError = true;
}

$pendingAppointments = [];
$pendingLoadError = false;
try {
    $pendingAppointments = $fetchRows(
        "SELECT a.appointment_id, a.appointment_date, a.appointment_time,
                p.pet_name, p.species, p.breed_size,
                CASE
                    WHEN s.category IN ('Basic Groom', 'Premium Groom')
                    THEN CONCAT_WS(' - ', s.category, s.service_name)
                    ELSE s.service_name
                END AS service_display_name,
                s.price AS current_service_price,
                a.service_price_at_booking, a.service_price_snapshot_saved
         FROM appointments AS a
         INNER JOIN pets AS p ON p.pet_id = a.pet_id AND p.user_id = a.user_id
         INNER JOIN services AS s ON s.service_id = a.service_id
         WHERE a.status = 'Pending'
         ORDER BY a.appointment_date ASC, a.appointment_time ASC, a.appointment_id ASC
         LIMIT 4"
    );
} catch (mysqli_sql_exception $exception) {
    error_log('Pawsome admin dashboard pending appointments query failed: ' . $exception->getMessage());
    $pendingLoadError = true;
}

$todayAppointments = [];
$scheduleLoadError = false;
try {
    $todayAppointments = $fetchRows(
        "SELECT a.appointment_id, a.appointment_time, a.status,
                p.pet_name,
                CASE
                    WHEN s.category IN ('Basic Groom', 'Premium Groom')
                    THEN CONCAT_WS(' - ', s.category, s.service_name)
                    ELSE s.service_name
                END AS service_display_name
         FROM appointments AS a
         INNER JOIN pets AS p ON p.pet_id = a.pet_id AND p.user_id = a.user_id
         INNER JOIN services AS s ON s.service_id = a.service_id
         WHERE a.appointment_date = ?
           AND a.status NOT IN ('Cancelled', 'Rejected')
         ORDER BY a.appointment_time ASC, a.appointment_id ASC
         LIMIT 6",
        's',
        [$todayKey]
    );
} catch (mysqli_sql_exception $exception) {
    error_log('Pawsome admin dashboard today schedule query failed: ' . $exception->getMessage());
    $scheduleLoadError = true;
}

$revenueByDate = [];
$revenueLoadError = false;
try {
    foreach ($fetchRows(
        "SELECT appointment_date,
                COALESCE(SUM(service_price_at_booking), 0) AS service_value
         FROM appointments
         WHERE appointment_date >= ? AND appointment_date < ?
           AND status = 'Completed'
           AND service_price_snapshot_saved = 1
           AND service_price_at_booking IS NOT NULL
         GROUP BY appointment_date
         ORDER BY appointment_date",
        'ss',
        [$today->modify('-6 days')->format('Y-m-d'), $tomorrowKey]
    ) as $revenueRow) {
        $revenueByDate[(string) $revenueRow['appointment_date']] = (float) $revenueRow['service_value'];
    }
} catch (mysqli_sql_exception $exception) {
    error_log('Pawsome admin dashboard revenue query failed: ' . $exception->getMessage());
    $revenueLoadError = true;
}

$revenueDays = [];
for ($dayOffset = 6; $dayOffset >= 0; $dayOffset--) {
    $day = $today->modify('-' . $dayOffset . ' days');
    $dayKey = $day->format('Y-m-d');
    $revenueDays[] = [
        'date' => $dayKey,
        'label' => $day->format('D'),
        'value' => $revenueByDate[$dayKey] ?? 0.0,
        'is_today' => $dayKey === $todayKey,
    ];
}
$weeklyRevenue = array_sum(array_column($revenueDays, 'value'));
$maximumDailyRevenue = $revenueDays ? max(array_column($revenueDays, 'value')) : 0.0;

$statusCounts = [];
$statusLoadError = false;
try {
    foreach ($fetchRows(
        "SELECT COALESCE(status, 'Unknown') AS status, COUNT(*) AS status_count
         FROM appointments
         GROUP BY status"
    ) as $statusRow) {
        $statusCounts[(string) $statusRow['status']] = (int) $statusRow['status_count'];
    }
} catch (mysqli_sql_exception $exception) {
    error_log('Pawsome admin dashboard status query failed: ' . $exception->getMessage());
    $statusLoadError = true;
}
$statusColors = [
    'Pending' => '#FFC93C',
    'Confirmed' => '#2B55C9',
    'In Progress' => '#8B75E6',
    'Ready for Pickup' => '#24AB87',
    'Completed' => '#35BB60',
    'Cancelled' => '#E95F66',
    'Rejected' => '#D94855',
    'Unknown' => '#A7B2C7',
];
$statusTotal = array_sum($statusCounts);
$statusSegments = [];
$statusPosition = 0.0;
foreach ($statusCounts as $status => $count) {
    if ($count <= 0) {
        continue;
    }
    $nextPosition = $statusPosition + ($statusTotal > 0 ? ($count / $statusTotal * 100) : 0);
    $color = $statusColors[$status] ?? $statusColors['Unknown'];
    $statusSegments[] = $color . ' ' . number_format($statusPosition, 2, '.', '') . '% '
        . number_format($nextPosition, 2, '.', '') . '%';
    $statusPosition = $nextPosition;
}
$statusChartGradient = $statusSegments
    ? 'conic-gradient(' . implode(', ', $statusSegments) . ')'
    : 'conic-gradient(#E7EDF6 0% 100%)';

$popularServices = [];
$popularServicesLoadError = false;
try {
    $popularServices = $fetchRows(
        "SELECT CASE
                   WHEN s.category IN ('Basic Groom', 'Premium Groom')
                   THEN CONCAT_WS(' - ', s.category, s.service_name)
                   ELSE s.service_name
               END AS service_display_name,
               s.service_name, s.category, COUNT(a.appointment_id) AS booking_count
         FROM services AS s
         LEFT JOIN appointments AS a
            ON a.service_id = s.service_id
           AND a.status NOT IN ('Cancelled', 'Rejected')
         GROUP BY s.service_id, s.service_name, s.category
         HAVING COUNT(a.appointment_id) > 0
         ORDER BY booking_count DESC, s.service_name ASC
         LIMIT 3"
    );
} catch (mysqli_sql_exception $exception) {
    error_log('Pawsome admin dashboard popular services query failed: ' . $exception->getMessage());
    $popularServicesLoadError = true;
}

$teamWorkload = [];
$teamWorkloadLoadError = false;
try {
    $teamWorkload = $fetchRows(
        "SELECT gr.groomer_id,
                COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), 'Unknown account') AS account_name,
                COUNT(*) AS record_count
         FROM grooming_records AS gr
         LEFT JOIN users AS u ON u.user_id = gr.groomer_id
         WHERE gr.grooming_date >= ? AND gr.grooming_date < ?
         GROUP BY gr.groomer_id, u.first_name, u.last_name
         ORDER BY record_count DESC, account_name ASC
         LIMIT 3",
        'ss',
        [$weekStart->format('Y-m-d'), $nextWeekStart->format('Y-m-d')]
    );
} catch (mysqli_sql_exception $exception) {
    error_log('Pawsome admin dashboard team workload query failed: ' . $exception->getMessage());
    $teamWorkloadLoadError = true;
}

$recentReviews = [];
$reviewsLoadError = false;
try {
    $recentReviews = $fetchRows(
        "SELECT r.review_id, r.rating, r.feedback, r.status, r.created_at,
                u.first_name, u.last_name, p.pet_name
         FROM reviews AS r
         INNER JOIN appointments AS a
            ON a.appointment_id = r.appointment_id AND a.user_id = r.user_id
         INNER JOIN users AS u ON u.user_id = r.user_id AND u.role = 'customer'
         INNER JOIN pets AS p ON p.pet_id = a.pet_id AND p.user_id = a.user_id
         WHERE r.status <> 'Removed'
         ORDER BY r.created_at DESC, r.review_id DESC
         LIMIT 3"
    );
} catch (mysqli_sql_exception $exception) {
    error_log('Pawsome admin dashboard recent reviews query failed: ' . $exception->getMessage());
    $reviewsLoadError = true;
}

$recentAppointments = [];
$recentAppointmentsLoadError = false;
try {
    $recentAppointments = $fetchRows(
        'SELECT a.appointment_id, a.appointment_date, a.appointment_time, a.status,
                u.first_name, u.last_name, p.pet_name,
                CASE
                    WHEN s.category IN (\'Basic Groom\', \'Premium Groom\')
                    THEN CONCAT_WS(\' - \', s.category, s.service_name)
                    ELSE s.service_name
                END AS service_display_name,
                s.price AS current_service_price,
                a.service_price_at_booking, a.service_price_snapshot_saved
         FROM appointments AS a
         INNER JOIN users AS u ON u.user_id = a.user_id
         INNER JOIN pets AS p ON p.pet_id = a.pet_id AND p.user_id = a.user_id
         INNER JOIN services AS s ON s.service_id = a.service_id
         ORDER BY a.created_at DESC, a.appointment_id DESC
         LIMIT 4'
    );
} catch (mysqli_sql_exception $exception) {
    error_log('Pawsome admin dashboard recent appointments query failed: ' . $exception->getMessage());
    $recentAppointmentsLoadError = true;
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
$reviewExcerpt = static function (string $feedback): string {
    $feedback = trim($feedback);
    if (auth_string_length($feedback) <= 86) {
        return $feedback;
    }
    return function_exists('mb_substr')
        ? mb_substr($feedback, 0, 83, 'UTF-8') . '...'
        : substr($feedback, 0, 83) . '...';
};
$todayAppointmentCount = $summaryLoadError
    ? null
    : (int) ($dashboardSummary['today_appointments'] ?? 0);
$pendingAppointmentCount = $summaryLoadError
    ? null
    : (int) ($dashboardSummary['pending_appointments'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Pawsome administration dashboard.">
    <meta name="theme-color" content="#fbf8ff">
    <title>Admin Dashboard | Pawsome</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800;900&family=Nunito:wght@500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/shared/portal.css">
    <link rel="stylesheet" href="../assets/css/admin/dashboard.css">
</head>
<body class="customer-app admin-app">
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
                    <input type="hidden" name="csrf_token" value="<?= auth_escape(auth_csrf_token()) ?>">
                    <button class="button button-primary button-small admin-logout" type="submit">Log out</button>
                </form>
            </header>

            <main class="customer-main admin-main admin-dashboard-main" id="admin-main">
                <section class="customer-page-heading admin-page-heading admin-dashboard-date-heading" aria-labelledby="dashboard-date-title">
                    <div>
                        <span class="eyebrow">Admin area</span>
                        <h1 id="dashboard-date-title">Dashboard <span>Overview</span></h1>
                        <p>A snapshot of Pawsome appointments and customers.</p>
                        <div class="admin-dashboard-today-summary">
                            <time datetime="<?= auth_escape($todayKey) ?>"><?= auth_escape($manilaNow->format('l, F j, Y')) ?></time>
                            <?php if ($todayAppointmentCount === null): ?>
                                <span aria-hidden="true">·</span>
                                <span>Today's appointment count is unavailable.</span>
                            <?php elseif ($todayAppointmentCount > 0): ?>
                                <span aria-hidden="true">·</span>
                                <span>
                                    <?= number_format($todayAppointmentCount) ?>
                                    appointment<?= $todayAppointmentCount === 1 ? '' : 's' ?> today
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>

                <?php if (is_string($appointmentActionSuccess) && $appointmentActionSuccess !== ''): ?>
                    <p class="admin-alert admin-dashboard-flash is-success" role="status"><?= auth_escape($appointmentActionSuccess) ?></p>
                <?php endif; ?>
                <?php if (is_string($appointmentActionError) && $appointmentActionError !== ''): ?>
                    <p class="admin-alert admin-dashboard-flash" role="alert"><?= auth_escape($appointmentActionError) ?></p>
                <?php endif; ?>
                <?php if ($summaryLoadError): ?>
                    <p class="admin-alert" role="alert">We couldn't load the dashboard totals. Please try again later.</p>
                <?php endif; ?>

                <section class="admin-overview-grid" aria-label="Dashboard totals">
                    <?php
                    $overviewCards = [
                        ['Total Appointments', 'total_appointments', 'All recorded appointments', 'calendar'],
                        ['Pending Appointments', 'pending_appointments', 'Awaiting a decision', 'clock'],
                        ['Total Customers', 'total_customers', 'Registered customer accounts', 'customers'],
                        ['Registered Pets', 'registered_pets', 'Pets in customer profiles', 'paw'],
                    ];
                    ?>
                    <?php foreach ($overviewCards as [$label, $key, $description, $icon]): ?>
                        <article class="admin-overview-card">
                            <span class="admin-overview-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" focusable="false">
                                    <?php if ($icon === 'calendar'): ?>
                                        <rect x="4" y="5" width="16" height="16" rx="2"></rect>
                                        <path d="M8 3v4m8-4v4M4 10h16"></path>
                                    <?php elseif ($icon === 'clock'): ?>
                                        <circle cx="12" cy="12" r="9"></circle>
                                        <path d="M12 7v5l3 2"></path>
                                    <?php elseif ($icon === 'customers'): ?>
                                        <circle cx="9" cy="8" r="3"></circle>
                                        <path d="M3 20v-1a6 6 0 0 1 12 0v1m1-14a3 3 0 0 1 0 6m2 3a5 5 0 0 1 3 4.5v.5"></path>
                                    <?php else: ?>
                                        <circle cx="6.5" cy="7" r="2"></circle>
                                        <circle cx="11" cy="5.5" r="2"></circle>
                                        <circle cx="15.5" cy="6.5" r="2"></circle>
                                        <circle cx="19" cy="10" r="2"></circle>
                                        <path d="M12.3 10.2c-2.8 0-5.8 3.3-5.8 6.2a3 3 0 0 0 4 2.8 5 5 0 0 1 3.6 0 3 3 0 0 0 4-2.8c0-2.9-3-6.2-5.8-6.2Z"></path>
                                    <?php endif; ?>
                                </svg>
                            </span>
                            <span class="admin-overview-label"><?= auth_escape($label) ?></span>
                            <strong class="admin-overview-value">
                                <?= $summaryLoadError ? '—' : number_format((int) ($dashboardSummary[$key] ?? 0)) ?>
                            </strong>
                            <span class="admin-overview-description"><?= auth_escape($description) ?></span>
                        </article>
                    <?php endforeach; ?>
                </section>

                <div class="admin-dashboard-top-grid">
                    <section class="admin-panel admin-dashboard-card" aria-labelledby="dashboard-action-needed-title">
                        <div class="admin-panel-heading">
                            <div>
                                <span class="eyebrow">Action needed</span>
                                <h2 id="dashboard-action-needed-title">Needs Attention</h2>
                            </div>
                            <span class="admin-panel-count"><?= $pendingAppointmentCount === null ? '—' : number_format($pendingAppointmentCount) ?></span>
                        </div>
                        <div class="admin-dashboard-content">
                            <?php if ($pendingLoadError): ?>
                                <p class="admin-panel-message" role="alert">We couldn't load pending appointments. Please try again later.</p>
                            <?php elseif (!$pendingAppointments): ?>
                                <p class="admin-panel-message">There are no pending appointments to review.</p>
                            <?php else: ?>
                                <ul class="admin-dashboard-action-list">
                                    <?php foreach ($pendingAppointments as $appointment): ?>
                                        <?php
                                        $appointmentDateTime = new DateTimeImmutable(
                                            (string) $appointment['appointment_date'] . ' ' . (string) $appointment['appointment_time'],
                                            $manilaTimezone
                                        );
                                        $priceDisplay = appointment_price_display($appointment);
                                        $petDescription = array_filter([
                                            (string) ($appointment['species'] ?? ''),
                                            (string) $appointment['service_display_name'],
                                        ], static fn (string $value): bool => trim($value) !== '');
                                        ?>
                                        <li class="admin-dashboard-action-item">
                                            <div class="admin-dashboard-action-copy">
                                                <a class="admin-dashboard-item-title" href="appointment-details.php?id=<?= (int) $appointment['appointment_id'] ?>">
                                                    #<?= (int) $appointment['appointment_id'] ?> · <?= auth_escape((string) $appointment['pet_name']) ?>
                                                </a>
                                                <p><?= auth_escape(implode(' · ', $petDescription)) ?></p>
                                                <span class="admin-dashboard-item-meta">
                                                    <?= auth_escape($appointmentDateTime->format('M j, g:i A')) ?>
                                                    · <?= auth_escape($priceDisplay['formatted']) ?>
                                                </span>
                                            </div>
                                            <div class="admin-dashboard-action-buttons">
                                                <?php foreach (['confirm', 'reject'] as $actionKey): ?>
                                                    <?php $transition = $actionTransitions[$actionKey]; ?>
                                                    <form
                                                        method="post"
                                                        action="appointments.php"
                                                        onsubmit="return confirm('<?= auth_escape($transition['confirmation_prompt']) ?>');"
                                                    >
                                                        <input type="hidden" name="csrf_token" value="<?= auth_escape($csrfToken) ?>">
                                                        <input type="hidden" name="appointment_id" value="<?= (int) $appointment['appointment_id'] ?>">
                                                        <input type="hidden" name="appointment_action" value="<?= auth_escape($actionKey) ?>">
                                                        <input type="hidden" name="return_to" value="dashboard">
                                                        <button class="admin-dashboard-action-button is-<?= auth_escape($transition['button_class']) ?>" type="submit"><?= auth_escape($transition['label']) ?></button>
                                                    </form>
                                                <?php endforeach; ?>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                            <a class="admin-dashboard-card-link" href="appointments.php?status=Pending">
                                View all <?= $pendingAppointmentCount === null ? 'pending' : number_format($pendingAppointmentCount) . ' pending' ?>
                                <span aria-hidden="true">→</span>
                            </a>
                        </div>
                    </section>

                    <section class="admin-panel admin-dashboard-card" aria-labelledby="dashboard-schedule-title">
                        <div class="admin-panel-heading">
                            <div>
                                <span class="eyebrow">Today</span>
                                <h2 id="dashboard-schedule-title">Today's Schedule</h2>
                            </div>
                        </div>
                        <div class="admin-dashboard-content">
                            <?php if ($scheduleLoadError): ?>
                                <p class="admin-panel-message" role="alert">We couldn't load today's schedule. Please try again later.</p>
                            <?php elseif (!$todayAppointments): ?>
                                <p class="admin-panel-message">No active appointments are scheduled for today.</p>
                            <?php else: ?>
                                <ul class="admin-dashboard-schedule-list">
                                    <?php foreach ($todayAppointments as $appointment): ?>
                                        <?php
                                        $status = is_string($appointment['status']) ? $appointment['status'] : 'Unknown';
                                        $statusClass = $statusClasses[$status] ?? 'unknown';
                                        $appointmentTime = new DateTimeImmutable(
                                            $todayKey . ' ' . (string) $appointment['appointment_time'],
                                            $manilaTimezone
                                        );
                                        ?>
                                        <li class="admin-dashboard-schedule-item">
                                            <time datetime="<?= auth_escape($appointmentTime->format('Y-m-d\TH:i:s')) ?>"><?= auth_escape($appointmentTime->format('g:i A')) ?></time>
                                            <span class="admin-dashboard-schedule-description">
                                                <strong><?= auth_escape((string) $appointment['pet_name']) ?></strong>
                                                <small><?= auth_escape((string) $appointment['service_display_name']) ?></small>
                                            </span>
                                            <span class="customer-status customer-status--<?= auth_escape($statusClass) ?>"><?= auth_escape($status) ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                            <a class="admin-dashboard-card-link" href="appointments.php?<?= auth_escape(http_build_query([
                                'from_date' => $todayKey,
                                'to_date' => $todayKey,
                            ])) ?>">Open calendar <span aria-hidden="true">→</span></a>
                        </div>
                    </section>
                </div>

                <div class="admin-dashboard-middle-grid">
                    <section class="admin-panel admin-dashboard-card" aria-labelledby="dashboard-revenue-title">
                        <div class="admin-panel-heading">
                            <div>
                                <span class="eyebrow">Performance · last 7 days</span>
                                <h2 id="dashboard-revenue-title">Revenue</h2>
                                <p class="admin-dashboard-panel-note">Completed service value · <?= auth_escape($today->modify('-6 days')->format('M j')) ?>–<?= auth_escape($today->format('M j')) ?></p>
                            </div>
                            <strong class="admin-dashboard-revenue-total">₱<?= number_format($weeklyRevenue, 2) ?></strong>
                        </div>
                        <div class="admin-dashboard-content">
                            <?php if ($revenueLoadError): ?>
                                <p class="admin-panel-message" role="alert">We couldn't load revenue data. Please try again later.</p>
                            <?php else: ?>
                                <div class="admin-dashboard-revenue-chart" role="img" aria-label="Completed service value over the last seven days">
                                    <?php foreach ($revenueDays as $day): ?>
                                        <?php
                                        $barHeight = $maximumDailyRevenue > 0
                                            ? max(4, round((float) $day['value'] / $maximumDailyRevenue * 100, 1))
                                            : 4;
                                        ?>
                                        <div class="admin-dashboard-revenue-day">
                                            <span class="admin-dashboard-revenue-value">₱<?= number_format((float) $day['value'], 0) ?></span>
                                            <div class="admin-dashboard-revenue-bar-track" title="<?= auth_escape($day['date'] . ' · ₱' . number_format((float) $day['value'], 2)) ?>">
                                                <span class="admin-dashboard-revenue-bar<?= $day['is_today'] ? ' is-today' : '' ?>" style="--admin-dashboard-bar-height: <?= number_format($barHeight, 1, '.', '') ?>%"></span>
                                            </div>
                                            <span class="admin-dashboard-revenue-day-label"><?= auth_escape($day['label']) ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <p class="admin-dashboard-disclaimer">Completed service value is not a payment confirmation.</p>
                            <?php endif; ?>
                        </div>
                    </section>

                    <section class="admin-panel admin-dashboard-card" aria-labelledby="dashboard-status-title">
                        <div class="admin-panel-heading">
                            <div>
                                <span class="eyebrow">Appointment breakdown</span>
                                <h2 id="dashboard-status-title">Status Overview</h2>
                            </div>
                        </div>
                        <div class="admin-dashboard-content">
                            <?php if ($statusLoadError): ?>
                                <p class="admin-panel-message" role="alert">We couldn't load appointment statuses. Please try again later.</p>
                            <?php elseif (!$statusTotal): ?>
                                <p class="admin-panel-message">No appointment status data is available yet.</p>
                            <?php else: ?>
                                <div class="admin-dashboard-status-overview">
                                    <div
                                        class="admin-dashboard-status-chart"
                                        role="img"
                                        aria-label="<?= number_format($statusTotal) ?> appointments in total"
                                        style="--admin-dashboard-status-gradient: <?= auth_escape($statusChartGradient) ?>"
                                    >
                                        <span><?= number_format($statusTotal) ?></span>
                                    </div>
                                    <ul class="admin-dashboard-status-legend">
                                        <?php foreach ($statusCounts as $status => $count): ?>
                                            <?php if ($count < 1) { continue; } ?>
                                            <?php $statusClass = $statusClasses[$status] ?? 'unknown'; ?>
                                            <li>
                                                <span class="admin-dashboard-status-dot is-<?= auth_escape($statusClass) ?>" aria-hidden="true"></span>
                                                <span class="customer-status customer-status--<?= auth_escape($statusClass) ?>"><?= auth_escape($status) ?></span>
                                                <strong><?= number_format($count) ?></strong>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>
                        </div>
                    </section>
                </div>

                <div class="admin-dashboard-bottom-grid">
                    <section class="admin-panel admin-dashboard-card" aria-labelledby="dashboard-services-title">
                        <div class="admin-panel-heading">
                            <div>
                                <span class="eyebrow">Popular</span>
                                <h2 id="dashboard-services-title">Top Services</h2>
                            </div>
                        </div>
                        <div class="admin-dashboard-content">
                            <?php if ($popularServicesLoadError): ?>
                                <p class="admin-panel-message" role="alert">We couldn't load popular services. Please try again later.</p>
                            <?php elseif (!$popularServices): ?>
                                <p class="admin-panel-message">Service popularity will appear after bookings are made.</p>
                            <?php else: ?>
                                <ul class="admin-dashboard-compact-list">
                                    <?php foreach ($popularServices as $service): ?>
                                        <li>
                                            <span>
                                                <strong><?= auth_escape((string) $service['service_display_name']) ?></strong>
                                            </span>
                                            <strong><?= number_format((int) $service['booking_count']) ?></strong>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </section>

                    <section class="admin-panel admin-dashboard-card" aria-labelledby="dashboard-team-title">
                        <div class="admin-panel-heading">
                            <div>
                                <span class="eyebrow">Team · this week</span>
                                <h2 id="dashboard-team-title">Grooming Activity</h2>
                            </div>
                        </div>
                        <div class="admin-dashboard-content">
                            <?php if ($teamWorkloadLoadError): ?>
                                <p class="admin-panel-message" role="alert">We couldn't load grooming activity. Please try again later.</p>
                            <?php elseif (!$teamWorkload): ?>
                                <p class="admin-panel-message">No grooming records have been created this week.</p>
                            <?php else: ?>
                                <ul class="admin-dashboard-compact-list">
                                    <?php foreach ($teamWorkload as $teamMember): ?>
                                        <li>
                                            <span>
                                                <strong><?= auth_escape((string) $teamMember['account_name']) ?></strong>
                                                <small>Records created</small>
                                            </span>
                                            <strong><?= number_format((int) $teamMember['record_count']) ?></strong>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                            <p class="admin-dashboard-disclaimer">Counts follow the account that saved each record; staff assignments are not tracked.</p>
                        </div>
                    </section>

                    <section class="admin-panel admin-dashboard-card" aria-labelledby="dashboard-reviews-title">
                        <div class="admin-panel-heading">
                            <div>
                                <span class="eyebrow">Feedback</span>
                                <h2 id="dashboard-reviews-title">Recent Reviews</h2>
                            </div>
                        </div>
                        <div class="admin-dashboard-content">
                            <?php if ($reviewsLoadError): ?>
                                <p class="admin-panel-message" role="alert">We couldn't load recent reviews. Please try again later.</p>
                            <?php elseif (!$recentReviews): ?>
                                <p class="admin-panel-message">Customer feedback will appear here when reviews are submitted.</p>
                            <?php else: ?>
                                <ul class="admin-dashboard-review-list">
                                    <?php foreach ($recentReviews as $review): ?>
                                        <?php
                                        $rating = max(0, min(5, (int) $review['rating']));
                                        $reviewDate = new DateTimeImmutable((string) $review['created_at'], $manilaTimezone);
                                        $reviewStatus = (string) $review['status'];
                                        ?>
                                        <li>
                                            <div class="admin-dashboard-review-heading">
                                                <span class="admin-dashboard-review-stars" aria-label="<?= $rating ?> out of 5 stars"><?= str_repeat('★', $rating) ?><span><?= str_repeat('☆', 5 - $rating) ?></span></span>
                                                <span class="admin-dashboard-review-status"><?= auth_escape($reviewStatus) ?></span>
                                            </div>
                                            <p><?= auth_escape($reviewExcerpt((string) $review['feedback'])) ?></p>
                                            <small><?= auth_escape(trim((string) $review['first_name'] . ' ' . (string) $review['last_name'])) ?> · <?= auth_escape($reviewDate->format('M j')) ?></small>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                            <a class="admin-dashboard-card-link" href="reviews.php">View feedback <span aria-hidden="true">→</span></a>
                        </div>
                    </section>
                </div>

                <section class="admin-panel admin-dashboard-card admin-dashboard-activity" aria-labelledby="dashboard-activity-title">
                    <div class="admin-panel-heading">
                        <div>
                            <span class="eyebrow">Latest activity</span>
                            <h2 id="dashboard-activity-title">Recent Appointments</h2>
                        </div>
                        <a class="admin-dashboard-card-link" href="appointments.php">View all <span aria-hidden="true">→</span></a>
                    </div>
                    <?php if ($recentAppointmentsLoadError): ?>
                        <p class="admin-panel-message" role="alert">We couldn't load recent appointments. Please try again later.</p>
                    <?php elseif (!$recentAppointments): ?>
                        <p class="admin-panel-message">No appointments have been booked yet.</p>
                    <?php else: ?>
                        <div class="admin-table-scroll">
                            <table class="admin-table admin-dashboard-activity-table">
                                <thead>
                                    <tr>
                                        <th scope="col">Ref</th>
                                        <th scope="col">Date</th>
                                        <th scope="col">Customer</th>
                                        <th scope="col">Pet</th>
                                        <th scope="col">Service</th>
                                        <th scope="col">Price</th>
                                        <th scope="col">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentAppointments as $appointment): ?>
                                        <?php
                                        $status = is_string($appointment['status']) ? $appointment['status'] : 'Unknown';
                                        $statusClass = $statusClasses[$status] ?? 'unknown';
                                        $priceDisplay = appointment_price_display($appointment);
                                        $appointmentDateTime = new DateTimeImmutable(
                                            (string) $appointment['appointment_date'] . ' ' . (string) $appointment['appointment_time'],
                                            $manilaTimezone
                                        );
                                        ?>
                                        <tr>
                                            <td><a class="admin-reference" href="appointment-details.php?id=<?= (int) $appointment['appointment_id'] ?>">#<?= (int) $appointment['appointment_id'] ?></a></td>
                                            <td>
                                                <strong><?= auth_escape($appointmentDateTime->format('M j')) ?></strong>
                                                <span class="admin-table-muted"><?= auth_escape($appointmentDateTime->format('g:i A')) ?></span>
                                            </td>
                                            <td><?= auth_escape(trim((string) $appointment['first_name'] . ' ' . (string) $appointment['last_name'])) ?></td>
                                            <td><?= auth_escape((string) $appointment['pet_name']) ?></td>
                                            <td><?= auth_escape((string) $appointment['service_display_name']) ?></td>
                                            <td title="<?= auth_escape($priceDisplay['source']) ?>"><?= auth_escape($priceDisplay['formatted']) ?></td>
                                            <td><span class="customer-status customer-status--<?= auth_escape($statusClass) ?>"><?= auth_escape($status) ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>
            </main>

            <footer class="customer-footer">
                <p>&copy; <?= $manilaNow->format('Y') ?> Pawsome. Made with care for pets and their people.</p>
            </footer>
        </div>
    </div>
</body>
</html>
