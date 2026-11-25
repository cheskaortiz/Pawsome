<?php
require_once __DIR__ . '/../includes/auth.php';
auth_require_admin();
require_once __DIR__ . '/../config/database.php';

$adminFirstName = (string) ($_SESSION['first_name'] ?? 'admin');
$summary = null;
$summaryLoadError = false;
$statusCounts = [
    'Pending' => 0,
    'Confirmed' => 0,
    'Completed' => 0,
    'Cancelled' => 0,
    'Rejected' => 0,
];
$statusLoadError = false;
$recentAppointments = [];
$recentAppointmentsLoadError = false;

try {
    $statement = $conn->prepare(
        "SELECT
            (SELECT COUNT(*) FROM appointments) AS total_appointments,
            (SELECT COUNT(*) FROM appointments WHERE status = 'Pending') AS pending_appointments,
            (SELECT COUNT(*) FROM users WHERE role = 'customer') AS total_customers,
            (SELECT COUNT(*) FROM pets) AS registered_pets"
    );
    $statement->execute();
    $summary = $statement->get_result()->fetch_assoc();
    $statement->close();
} catch (mysqli_sql_exception $exception) {
    error_log('Pawsome admin dashboard summary query failed: ' . $exception->getMessage());
    $summaryLoadError = true;
}

try {
    $statement = $conn->prepare(
        "SELECT COALESCE(status, 'Unknown') AS status, COUNT(*) AS status_count
         FROM appointments
         GROUP BY status"
    );
    $statement->execute();
    foreach ($statement->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $status = (string) $row['status'];
        if (!array_key_exists($status, $statusCounts)) {
            $statusCounts[$status] = 0;
        }
        $statusCounts[$status] = (int) $row['status_count'];
    }
    $statement->close();
} catch (mysqli_sql_exception $exception) {
    error_log('Pawsome admin dashboard status query failed: ' . $exception->getMessage());
    $statusLoadError = true;
}

try {
    $statement = $conn->prepare(
        'SELECT a.appointment_id, a.appointment_date, a.appointment_time, a.status,
                u.first_name, u.last_name, p.pet_name, s.service_name
         FROM appointments AS a
         INNER JOIN users AS u ON u.user_id = a.user_id
         INNER JOIN pets AS p ON p.pet_id = a.pet_id AND p.user_id = a.user_id
         INNER JOIN services AS s ON s.service_id = a.service_id
         ORDER BY a.created_at DESC, a.appointment_id DESC
         LIMIT 8'
    );
    $statement->execute();
    $recentAppointments = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
} catch (mysqli_sql_exception $exception) {
    error_log('Pawsome admin dashboard recent appointments query failed: ' . $exception->getMessage());
    $recentAppointmentsLoadError = true;
}

$statusClasses = [
    'Pending' => 'pending',
    'Confirmed' => 'confirmed',
    'Completed' => 'completed',
    'Cancelled' => 'cancelled',
    'Rejected' => 'rejected',
    'Unknown' => 'unknown',
];
$statusTotal = array_sum($statusCounts);
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
    <link rel="stylesheet" href="../assets/css/customer.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body class="customer-app admin-app">
    <a class="skip-link" href="#admin-main">Skip to content</a>
    <div class="customer-layout admin-layout">
        <aside class="customer-sidebar admin-sidebar">
            <a class="brand customer-brand" href="dashboard.php" aria-label="Pawsome admin home">
                <span class="brand-mark" aria-hidden="true">
                    <svg viewBox="0 0 40 40" focusable="false">
                        <path d="M20 31.3c-1.6 0-3.1-1.1-5.4-1.1-2.1 0-3.6 1-5.3.2-2.4-1.2-2.1-4.7.6-7.5 2.4-2.5 6-4.4 10.1-4.4s7.7 1.9 10.1 4.4c2.7 2.8 3 6.3.6 7.5-1.7.8-3.2-.2-5.3-.2-2.3 0-3.8 1.1-5.4 1.1Z" fill="currentColor"/>
                        <ellipse cx="9.8" cy="15.3" rx="3.3" ry="4.3" transform="rotate(-27 9.8 15.3)" fill="currentColor"/>
                        <ellipse cx="17.1" cy="10.6" rx="3.2" ry="4.2" transform="rotate(-10 17.1 10.6)" fill="currentColor"/>
                        <ellipse cx="24.9" cy="10.6" rx="3.2" ry="4.2" transform="rotate(10 24.9 10.6)" fill="currentColor"/>
                        <ellipse cx="32.2" cy="15.3" rx="3.3" ry="4.3" transform="rotate(27 32.2 15.3)" fill="currentColor"/>
                    </svg>
                </span>
                <span class="brand-name">Paw<span>some</span></span>
            </a>

            <nav class="customer-navigation admin-navigation" aria-label="Admin navigation">
                <a class="customer-nav-link is-active" href="dashboard.php" aria-current="page">
                    <span class="customer-nav-indicator" aria-hidden="true"></span>
                    Dashboard
                </a>
                <a class="customer-nav-link" href="#recent-appointments">
                    <span class="customer-nav-indicator" aria-hidden="true"></span>
                    Appointments
                </a>
                <span class="customer-nav-link admin-nav-placeholder" aria-disabled="true" title="Coming soon">
                    <span class="customer-nav-indicator" aria-hidden="true"></span>
                    Customers
                    <span class="admin-nav-coming-soon">Soon</span>
                </span>
                <span class="customer-nav-link admin-nav-placeholder" aria-disabled="true" title="Coming soon">
                    <span class="customer-nav-indicator" aria-hidden="true"></span>
                    Pets
                    <span class="admin-nav-coming-soon">Soon</span>
                </span>
                <span class="customer-nav-link admin-nav-placeholder" aria-disabled="true" title="Coming soon">
                    <span class="customer-nav-indicator" aria-hidden="true"></span>
                    Services &amp; Prices
                    <span class="admin-nav-coming-soon">Soon</span>
                </span>
                <span class="customer-nav-link admin-nav-placeholder" aria-disabled="true" title="Coming soon">
                    <span class="customer-nav-indicator" aria-hidden="true"></span>
                    Grooming Records
                    <span class="admin-nav-coming-soon">Soon</span>
                </span>
            </nav>

            <div class="customer-sidebar-bottom">
                <a class="customer-public-link" href="../index.php">← Pawsome website</a>
            </div>
        </aside>

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

            <main class="customer-main admin-main" id="admin-main">
                <section class="customer-page-heading admin-page-heading">
                    <div>
                        <span class="eyebrow">Admin area</span>
                        <h1>Dashboard <span>Overview</span></h1>
                        <p>A snapshot of Pawsome appointments and customers.</p>
                    </div>
                </section>

                <?php if ($summaryLoadError): ?>
                    <p class="admin-alert" role="alert">We couldn't load the dashboard totals. Please try again later.</p>
                <?php endif; ?>
                <section class="admin-overview-grid" aria-label="Dashboard totals">
                    <?php
                    $overviewCards = [
                        ['Total Appointments', 'total_appointments', 'All recorded appointments'],
                        ['Pending Appointments', 'pending_appointments', 'Awaiting a decision'],
                        ['Total Customers', 'total_customers', 'Registered customer accounts'],
                        ['Registered Pets', 'registered_pets', 'Pets in customer profiles'],
                    ];
                    ?>
                    <?php foreach ($overviewCards as [$label, $key, $description]): ?>
                        <article class="admin-overview-card">
                            <span class="admin-overview-label"><?= auth_escape($label) ?></span>
                            <strong class="admin-overview-value">
                                <?= $summary === null ? '—' : number_format((int) $summary[$key]) ?>
                            </strong>
                            <span class="admin-overview-description"><?= auth_escape($description) ?></span>
                        </article>
                    <?php endforeach; ?>
                </section>

                <div class="admin-dashboard-grid">
                    <section class="admin-panel" id="recent-appointments" aria-labelledby="recent-appointments-title">
                        <div class="admin-panel-heading">
                            <div>
                                <span class="eyebrow">Latest activity</span>
                                <h2 id="recent-appointments-title">Recent Appointments</h2>
                            </div>
                            <span class="admin-panel-count"><?= $recentAppointmentsLoadError ? '—' : count($recentAppointments) ?></span>
                        </div>
                        <?php if ($recentAppointmentsLoadError): ?>
                            <p class="admin-panel-message" role="alert">We couldn't load recent appointments. Please try again later.</p>
                        <?php elseif (!$recentAppointments): ?>
                            <p class="admin-panel-message">No appointments have been booked yet.</p>
                        <?php else: ?>
                            <div class="admin-table-scroll">
                                <table class="admin-table">
                                    <thead>
                                        <tr>
                                            <th scope="col">Reference</th>
                                            <th scope="col">Date &amp; time</th>
                                            <th scope="col">Customer</th>
                                            <th scope="col">Pet</th>
                                            <th scope="col">Service</th>
                                            <th scope="col">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recentAppointments as $appointment): ?>
                                            <?php
                                            $status = is_string($appointment['status']) ? $appointment['status'] : 'Unknown';
                                            $statusClass = $statusClasses[$status] ?? 'unknown';
                                            $statusLabel = isset($statusClasses[$status]) ? $status : 'Unknown';
                                            $appointmentDateTime = new DateTimeImmutable(
                                                (string) $appointment['appointment_date'] . ' ' . (string) $appointment['appointment_time'],
                                                new DateTimeZone('Asia/Manila')
                                            );
                                            ?>
                                            <tr>
                                                <td class="admin-reference">#<?= (int) $appointment['appointment_id'] ?></td>
                                                <td>
                                                    <strong><?= auth_escape($appointmentDateTime->format('M j, Y')) ?></strong>
                                                    <span class="admin-table-muted"><?= auth_escape($appointmentDateTime->format('g:i A')) ?></span>
                                                </td>
                                                <td><?= auth_escape(trim((string) $appointment['first_name'] . ' ' . (string) $appointment['last_name'])) ?></td>
                                                <td><?= auth_escape((string) $appointment['pet_name']) ?></td>
                                                <td><?= auth_escape((string) $appointment['service_name']) ?></td>
                                                <td>
                                                    <span class="customer-status customer-status--<?= auth_escape($statusClass) ?>">
                                                        <?= auth_escape($statusLabel) ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </section>

                    <section class="admin-panel admin-status-panel" aria-labelledby="status-overview-title">
                        <div class="admin-panel-heading">
                            <div>
                                <span class="eyebrow">Appointment breakdown</span>
                                <h2 id="status-overview-title">Appointment Status Overview</h2>
                            </div>
                        </div>
                        <?php if ($statusLoadError): ?>
                            <p class="admin-panel-message" role="alert">We couldn't load the appointment status overview. Please try again later.</p>
                        <?php else: ?>
                            <div class="admin-status-list">
                                <?php foreach ($statusCounts as $status => $count): ?>
                                    <?php
                                    if ($status === 'Unknown' && $count === 0) {
                                        continue;
                                    }
                                    $statusClass = $statusClasses[$status] ?? 'unknown';
                                    $statusPercent = $statusTotal > 0 ? round($count / $statusTotal * 100, 1) : 0;
                                    ?>
                                    <div class="admin-status-item">
                                        <div class="admin-status-heading">
                                            <span class="customer-status customer-status--<?= auth_escape($statusClass) ?>">
                                                <?= auth_escape($status) ?>
                                            </span>
                                            <strong><?= number_format($count) ?></strong>
                                        </div>
                                        <div
                                            class="admin-status-track"
                                            role="progressbar"
                                            aria-label="<?= auth_escape($status) ?> appointments"
                                            aria-valuemin="0"
                                            aria-valuemax="100"
                                            aria-valuenow="<?= number_format($statusPercent, 1, '.', '') ?>"
                                        >
                                            <span class="admin-status-fill admin-status-fill--<?= auth_escape($statusClass) ?>" style="width: <?= number_format($statusPercent, 1, '.', '') ?>%"></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                </div>
            </main>

            <footer class="customer-footer">
                <p>&copy; <?= date('Y') ?> Pawsome. Made with care for pets and their people.</p>
            </footer>
        </div>
    </div>
</body>
</html>
