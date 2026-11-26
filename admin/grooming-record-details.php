<?php
require_once __DIR__ . '/../includes/auth.php';
auth_require_admin();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/appointment_price.php';

$adminFirstName = (string) ($_SESSION['first_name'] ?? 'admin');
$activeAdminPage = 'grooming-records';
$rawRecordId = $_GET['id'] ?? '';
$recordId = is_string($rawRecordId)
    ? filter_var($rawRecordId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;

if ($recordId === false) {
    http_response_code(404);
    exit('Grooming record not found.');
}

try {
    $statement = $conn->prepare(
        "SELECT gr.record_id, gr.appointment_id, gr.pet_id, gr.groomer_id,
                gr.grooming_date, gr.grooming_notes, gr.created_at,
                a.appointment_date, a.appointment_time, a.status AS appointment_status,
                a.service_price_at_booking, a.service_price_snapshot_saved,
                CASE
                    WHEN s.category IN ('Basic Groom', 'Premium Groom')
                    THEN CONCAT_WS(' - ', s.category, s.service_name)
                    ELSE s.service_name
                END AS service_display_name,
                s.category, s.species AS service_species, s.pet_size, s.duration,
                p.pet_name, p.species AS pet_species, p.breed, p.gender, p.age, p.birthday,
                p.photo_path, p.color_markings, p.weight_kg, p.breed_size, p.notes AS pet_notes,
                u.user_id AS customer_id, u.first_name AS customer_first_name,
                u.last_name AS customer_last_name, u.email AS customer_email, u.phone AS customer_phone,
                g.first_name AS groomer_first_name, g.last_name AS groomer_last_name,
                s.price AS current_service_price
         FROM grooming_records AS gr
         INNER JOIN appointments AS a ON a.appointment_id = gr.appointment_id
         INNER JOIN services AS s ON s.service_id = a.service_id
         INNER JOIN pets AS p ON p.pet_id = gr.pet_id AND p.user_id = a.user_id
         INNER JOIN users AS u ON u.user_id = a.user_id AND u.role = 'customer'
         LEFT JOIN users AS g ON g.user_id = gr.groomer_id
         WHERE gr.record_id = ?
         LIMIT 1"
    );
    $statement->bind_param('i', $recordId);
    $statement->execute();
    $record = $statement->get_result()->fetch_assoc();
    $statement->close();
} catch (mysqli_sql_exception $exception) {
    error_log('Pawsome admin grooming record detail query failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('We could not load this grooming record right now.');
}

if (!$record) {
    http_response_code(404);
    exit('Grooming record not found.');
}

$appointmentDateTime = new DateTimeImmutable(
    (string) $record['appointment_date'] . ' ' . (string) $record['appointment_time'],
    new DateTimeZone('Asia/Manila')
);
$groomingDate = new DateTimeImmutable((string) $record['grooming_date'], new DateTimeZone('Asia/Manila'));
$appointmentStatus = (string) ($record['appointment_status'] ?? 'Unknown');
$statusClasses = [
    'Pending' => 'pending',
    'Confirmed' => 'confirmed',
    'In Progress' => 'in-progress',
    'Ready for Pickup' => 'ready-for-pickup',
    'Completed' => 'completed',
    'Cancelled' => 'cancelled',
    'Rejected' => 'rejected',
];
$statusClass = $statusClasses[$appointmentStatus] ?? 'unknown';
$priceDisplay = appointment_price_display($record);
$customerName = trim((string) $record['customer_first_name'] . ' ' . (string) $record['customer_last_name']);
$groomerName = trim((string) ($record['groomer_first_name'] ?? '') . ' ' . (string) ($record['groomer_last_name'] ?? ''));
$petAge = $record['birthday'] !== null && $record['birthday'] !== ''
    ? (new DateTimeImmutable((string) $record['birthday'], new DateTimeZone('Asia/Manila')))
        ->diff(new DateTimeImmutable('today', new DateTimeZone('Asia/Manila')))->y . ' years'
    : ($record['age'] === null ? 'Not provided' : ((int) $record['age'] === 0 ? 'Under 1 year' : (int) $record['age'] . ' years'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Review a Pawsome grooming record.">
    <meta name="theme-color" content="#fbf8ff">
    <title>Grooming Record #<?= (int) $recordId ?> | Pawsome Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800;900&family=Nunito:wght@500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/shared/portal.css">
    <link rel="stylesheet" href="../assets/css/admin/dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin/grooming-records.css">
    <link rel="stylesheet" href="../assets/css/admin/grooming-record-details.css">
</head>
<body class="customer-app admin-app admin-grooming-record-details-page">
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

            <main class="customer-main admin-main admin-grooming-record-detail-main" id="admin-main">
                <a class="admin-grooming-back" href="grooming-records.php">&larr; Back to Grooming Records</a>

                <section class="customer-page-heading admin-page-heading admin-grooming-detail-heading">
                    <div>
                        <span class="eyebrow">Grooming history</span>
                        <h1>Record <span>#<?= (int) $recordId ?></span></h1>
                        <p><?= auth_escape((string) $record['pet_name']) ?> · <?= auth_escape($groomingDate->format('F j, Y')) ?></p>
                    </div>
                    <span class="customer-status customer-status--<?= auth_escape($statusClass) ?>">
                        Appointment <?= auth_escape($appointmentStatus) ?>
                    </span>
                </section>

                <div class="admin-grooming-detail-grid">
                    <section class="admin-panel admin-grooming-detail-card" aria-labelledby="appointment-service-title">
                        <div class="admin-panel-heading">
                            <div>
                                <span class="eyebrow">Linked appointment</span>
                                <h2 id="appointment-service-title">Appointment &amp; Service</h2>
                            </div>
                        </div>
                        <dl class="admin-grooming-detail-list">
                            <div><dt>Record ID</dt><dd>#<?= (int) $record['record_id'] ?></dd></div>
                            <div><dt>Appointment ID</dt><dd><a href="appointment-details.php?id=<?= (int) $record['appointment_id'] ?>">#<?= (int) $record['appointment_id'] ?></a></dd></div>
                            <div><dt>Scheduled date</dt><dd><?= auth_escape($appointmentDateTime->format('M j, Y')) ?></dd></div>
                            <div><dt>Scheduled time</dt><dd><?= auth_escape($appointmentDateTime->format('g:i A')) ?></dd></div>
                            <div><dt>Grooming date</dt><dd><?= auth_escape($groomingDate->format('M j, Y')) ?></dd></div>
                            <div><dt>Service</dt><dd><?= auth_escape((string) $record['service_display_name']) ?></dd></div>
                            <div><dt>Duration</dt><dd><?= (int) $record['duration'] ?> minutes</dd></div>
                            <div><dt>Price</dt><dd><?= auth_escape($priceDisplay['formatted']) ?><span class="admin-table-muted"><?= auth_escape($priceDisplay['source']) ?></span></dd></div>
                            <div><dt>Appointment status</dt><dd><?= auth_escape($appointmentStatus) ?></dd></div>
                            <div><dt>Recorded by</dt><dd><?= auth_escape($groomerName !== '' ? $groomerName : 'Not recorded') ?></dd></div>
                            <div><dt>Record created</dt><dd><?= auth_escape(date('M j, Y g:i A', strtotime((string) $record['created_at']))) ?></dd></div>
                        </dl>
                    </section>

                    <section class="admin-panel admin-grooming-detail-card" aria-labelledby="customer-information-title">
                        <div class="admin-panel-heading">
                            <div>
                                <span class="eyebrow">Pet parent</span>
                                <h2 id="customer-information-title">Customer Information</h2>
                            </div>
                        </div>
                        <dl class="admin-grooming-detail-list">
                            <div><dt>Name</dt><dd><?= auth_escape($customerName) ?></dd></div>
                            <div><dt>Email</dt><dd><?= auth_escape((string) $record['customer_email']) ?></dd></div>
                            <div><dt>Phone</dt><dd><?= auth_escape(trim((string) ($record['customer_phone'] ?? '')) ?: 'Not provided') ?></dd></div>
                            <div><dt>Customer profile</dt><dd><a href="customer-details.php?id=<?= (int) $record['customer_id'] ?>">View customer</a></dd></div>
                        </dl>
                    </section>
                </div>

                <section class="admin-panel admin-grooming-detail-card admin-grooming-pet-card" aria-labelledby="pet-profile-title">
                    <div class="admin-panel-heading">
                        <div>
                            <span class="eyebrow">Pet profile</span>
                            <h2 id="pet-profile-title">Pet Information</h2>
                        </div>
                        <a class="admin-grooming-secondary-link" href="pet-details.php?id=<?= (int) $record['pet_id'] ?>">View pet profile</a>
                    </div>
                    <div class="admin-grooming-pet-content">
                        <?php if (!empty($record['photo_path'])): ?>
                            <img
                                class="admin-grooming-pet-photo"
                                src="../customer/pet-media.php?pet_id=<?= (int) $record['pet_id'] ?>&amp;type=photo"
                                alt="<?= auth_escape((string) $record['pet_name']) ?>"
                            >
                        <?php else: ?>
                            <div class="admin-grooming-pet-photo admin-grooming-pet-placeholder" aria-hidden="true">🐾</div>
                        <?php endif; ?>
                        <div class="admin-grooming-pet-info">
                            <h3><?= auth_escape((string) $record['pet_name']) ?></h3>
                            <dl class="admin-grooming-detail-list">
                                <div><dt>Species</dt><dd><?= auth_escape((string) $record['pet_species']) ?></dd></div>
                                <div><dt>Breed</dt><dd><?= auth_escape(trim((string) ($record['breed'] ?? '')) ?: 'Not provided') ?></dd></div>
                                <div><dt>Gender</dt><dd><?= auth_escape((string) ($record['gender'] ?? 'Not provided')) ?></dd></div>
                                <div><dt>Birthday</dt><dd><?= !empty($record['birthday']) ? auth_escape(date('M j, Y', strtotime((string) $record['birthday']))) : 'Not provided' ?></dd></div>
                                <div><dt>Age</dt><dd><?= auth_escape($petAge) ?></dd></div>
                                <div><dt>Weight</dt><dd><?= $record['weight_kg'] !== null ? auth_escape(number_format((float) $record['weight_kg'], 2)) . ' kg' : 'Not provided' ?></dd></div>
                                <div><dt>Breed size</dt><dd><?= auth_escape(trim((string) ($record['breed_size'] ?? '')) ?: 'Not provided') ?></dd></div>
                                <div><dt>Color / markings</dt><dd><?= auth_escape(trim((string) ($record['color_markings'] ?? '')) ?: 'Not provided') ?></dd></div>
                            </dl>
                            <?php if (!empty($record['pet_notes'])): ?>
                                <div class="admin-grooming-pet-notes">
                                    <strong>Profile notes</strong>
                                    <p><?= nl2br(auth_escape((string) $record['pet_notes'])) ?></p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>

                <section class="admin-panel admin-grooming-detail-card" aria-labelledby="grooming-notes-title">
                    <div class="admin-panel-heading">
                        <div>
                            <span class="eyebrow">Recorded observations</span>
                            <h2 id="grooming-notes-title">Grooming Notes &amp; Observations</h2>
                        </div>
                    </div>
                    <?php if (trim((string) ($record['grooming_notes'] ?? '')) !== ''): ?>
                        <div class="admin-grooming-notes-content"><?= nl2br(auth_escape((string) $record['grooming_notes'])) ?></div>
                    <?php else: ?>
                        <p class="admin-grooming-empty">No grooming notes or observations were recorded.</p>
                    <?php endif; ?>
                </section>

                <div class="admin-grooming-unsupported-grid">
                    <section class="admin-panel admin-grooming-detail-card" aria-labelledby="grooming-photos-title">
                        <div class="admin-panel-heading">
                            <div>
                                <span class="eyebrow">Media</span>
                                <h2 id="grooming-photos-title">Before &amp; After Photos</h2>
                            </div>
                        </div>
                        <p class="admin-grooming-empty">Grooming record photos are not stored in the current database schema.</p>
                    </section>
                    <section class="admin-panel admin-grooming-detail-card" aria-labelledby="products-used-title">
                        <div class="admin-panel-heading">
                            <div>
                                <span class="eyebrow">Products</span>
                                <h2 id="products-used-title">Products Used</h2>
                            </div>
                        </div>
                        <p class="admin-grooming-empty">Products used are not stored in the current database schema.</p>
                    </section>
                    <section class="admin-panel admin-grooming-detail-card" aria-labelledby="pet-condition-title">
                        <div class="admin-panel-heading">
                            <div>
                                <span class="eyebrow">Condition</span>
                                <h2 id="pet-condition-title">Pet Condition</h2>
                            </div>
                        </div>
                        <p class="admin-grooming-empty">There is no structured pet-condition field in the current schema. Any observations recorded are shown in Grooming Notes.</p>
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
