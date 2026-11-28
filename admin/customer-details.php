<?php
require_once __DIR__ . '/../includes/auth.php';
auth_require_admin();
require_once __DIR__ . '/../config/database.php';

$activeAdminPage = 'customers';
$adminFirstName = (string) ($_SESSION['first_name'] ?? 'admin');
$rawCustomerId = $_GET['id'] ?? '';
$customerId = is_string($rawCustomerId)
    ? filter_var($rawCustomerId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;

if ($customerId === false) {
    http_response_code(404);
    exit('Customer not found.');
}

$customer = null;
$pets = [];
$appointments = [];
$groomingHistory = [];
$reviews = [];
$loadError = false;

try {
    $statement = $conn->prepare(
        "SELECT user_id, first_name, last_name, email, username, phone, created_at
         FROM users
         WHERE user_id = ? AND role = 'customer'
         LIMIT 1"
    );
    $statement->bind_param('i', $customerId);
    $statement->execute();
    $customer = $statement->get_result()->fetch_assoc();
    $statement->close();
} catch (mysqli_sql_exception $exception) {
    error_log('Pawsome admin customer detail query failed: ' . $exception->getMessage());
    $loadError = true;
}

if (!$customer) {
    if (!$loadError) {
        http_response_code(404);
        exit('Customer not found.');
    }
    http_response_code(500);
    exit('We could not load this customer right now.');
}

try {
    $statement = $conn->prepare(
        'SELECT pet_id, pet_name, species, breed, gender, age, birthday,
                photo_path, vaccination_proof_path, color_markings, weight_kg,
                breed_size, notes, created_at
         FROM pets
         WHERE user_id = ?
         ORDER BY created_at DESC, pet_id DESC'
    );
    $statement->bind_param('i', $customerId);
    $statement->execute();
    $pets = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();

    $statement = $conn->prepare(
        'SELECT a.appointment_id, a.appointment_date, a.appointment_time, a.status,
                p.pet_name,
                CASE
                    WHEN s.category IN (\'Basic Groom\', \'Premium Groom\')
                    THEN CONCAT_WS(\' - \', s.category, s.service_name)
                    ELSE s.service_name
                END AS service_display_name
         FROM appointments AS a
         INNER JOIN pets AS p ON p.pet_id = a.pet_id AND p.user_id = a.user_id
         INNER JOIN services AS s ON s.service_id = a.service_id
         WHERE a.user_id = ?
         ORDER BY a.appointment_date DESC, a.appointment_time DESC, a.appointment_id DESC'
    );
    $statement->bind_param('i', $customerId);
    $statement->execute();
    $appointments = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
} catch (mysqli_sql_exception $exception) {
    error_log('Pawsome admin customer profile query failed: ' . $exception->getMessage());
    $loadError = true;
}

try {
    $statement = $conn->prepare(
        "SELECT gr.record_id, gr.appointment_id, gr.grooming_date, gr.grooming_notes,
                p.pet_name,
                CASE
                    WHEN s.category IN ('Basic Groom', 'Premium Groom')
                    THEN CONCAT_WS(' - ', s.category, s.service_name)
                    ELSE s.service_name
                END AS service_display_name,
                g.first_name AS groomer_first_name, g.last_name AS groomer_last_name
         FROM grooming_records AS gr
         INNER JOIN appointments AS a ON a.appointment_id = gr.appointment_id
         INNER JOIN pets AS p ON p.pet_id = gr.pet_id AND p.user_id = a.user_id
         INNER JOIN services AS s ON s.service_id = a.service_id
         LEFT JOIN users AS g ON g.user_id = gr.groomer_id
         WHERE a.user_id = ?
         ORDER BY gr.grooming_date DESC, gr.record_id DESC"
    );
    $statement->bind_param('i', $customerId);
    $statement->execute();
    $groomingHistory = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
} catch (mysqli_sql_exception $exception) {
    error_log('Pawsome admin customer grooming history query failed: ' . $exception->getMessage());
    $loadError = true;
}

try {
    $statement = $conn->prepare(
        'SELECT r.review_id, r.appointment_id, r.rating, r.feedback, r.status, r.created_at,
                a.appointment_date, p.pet_name, s.category AS service_category,
                s.service_name
         FROM reviews AS r
         INNER JOIN appointments AS a
            ON a.appointment_id = r.appointment_id AND a.user_id = r.user_id
         INNER JOIN pets AS p ON p.pet_id = a.pet_id AND p.user_id = a.user_id
         INNER JOIN services AS s ON s.service_id = a.service_id
         WHERE r.user_id = ?
         ORDER BY r.created_at DESC, r.review_id DESC'
    );
    $statement->bind_param('i', $customerId);
    $statement->execute();
    $reviews = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
} catch (mysqli_sql_exception $exception) {
    error_log('Pawsome admin customer review history query failed: ' . $exception->getMessage());
    $loadError = true;
}

$customerName = trim((string) $customer['first_name'] . ' ' . (string) $customer['last_name']);
$customerNameParts = preg_split('/\s+/', $customerName, -1, PREG_SPLIT_NO_EMPTY) ?: [];
$customerInitials = '';
foreach (array_slice($customerNameParts, 0, 2) as $namePart) {
    $customerInitials .= function_exists('mb_substr')
        ? mb_substr($namePart, 0, 1, 'UTF-8')
        : substr($namePart, 0, 1);
}

$statusClasses = [
    'Pending' => 'pending',
    'Confirmed' => 'confirmed',
    'In Progress' => 'in-progress',
    'Ready for Pickup' => 'ready-for-pickup',
    'Completed' => 'completed',
    'Cancelled' => 'cancelled',
    'Rejected' => 'rejected',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="View Pawsome customer information.">
    <meta name="theme-color" content="#fbf8ff">
    <title>Customer Details | Pawsome Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800;900&family=Nunito:wght@500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/shared/portal.css">
    <link rel="stylesheet" href="../assets/css/admin/dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin/customers.css">
    <link rel="stylesheet" href="../assets/css/admin/customer-details.css">
</head>
<body class="customer-app admin-app admin-customer-details-page">
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

            <main class="customer-main admin-main admin-customer-detail-main" id="admin-main">
                <a class="admin-customer-back" href="customers.php">&larr; Back to Customers</a>

                <section class="customer-page-heading admin-page-heading admin-customer-detail-heading">
                    <div>
                        <span class="eyebrow">Customer profile</span>
                        <h1><?= auth_escape($customerName) ?> <span>#<?= (int) $customerId ?></span></h1>
                        <p>Review the customer's account, registered pets, and booking history.</p>
                    </div>
                </section>

                <?php if ($loadError): ?>
                    <p class="admin-alert" role="alert">Some customer information could not be loaded. Please try again later.</p>
                <?php endif; ?>

                <section class="admin-panel admin-customer-detail-card" aria-labelledby="customer-information-title">
                    <div class="admin-panel-heading">
                        <div>
                            <span class="eyebrow">Account</span>
                            <h2 id="customer-information-title">Customer Information</h2>
                        </div>
                        <span class="admin-customer-avatar admin-customer-detail-avatar" aria-hidden="true"><?= auth_escape($customerInitials ?: '?') ?></span>
                    </div>
                    <dl class="admin-customer-detail-list">
                        <div><dt>Full name</dt><dd><?= auth_escape($customerName) ?></dd></div>
                        <div><dt>Email</dt><dd><a href="mailto:<?= auth_escape((string) $customer['email']) ?>"><?= auth_escape((string) $customer['email']) ?></a></dd></div>
                        <div><dt>Phone number</dt><dd><?= auth_escape(trim((string) ($customer['phone'] ?? '')) ?: 'Not provided') ?></dd></div>
                        <div><dt>Username</dt><dd><?= auth_escape((string) $customer['username']) ?></dd></div>
                        <div><dt>Registration date</dt><dd><?= auth_escape(date('M j, Y · g:i A', strtotime((string) $customer['created_at']))) ?></dd></div>
                    </dl>
                </section>

                <section class="admin-panel admin-customer-detail-card" aria-labelledby="registered-pets-title">
                    <div class="admin-panel-heading">
                        <div>
                            <span class="eyebrow">Furry family</span>
                            <h2 id="registered-pets-title">Registered Pets</h2>
                        </div>
                        <span class="admin-panel-count"><?= count($pets) ?></span>
                    </div>

                    <?php if (!$pets): ?>
                        <p class="admin-customer-empty">This customer has no registered pets.</p>
                    <?php else: ?>
                        <div class="admin-customer-pet-grid">
                            <?php foreach ($pets as $pet): ?>
                                <article class="admin-customer-pet-card">
                                    <?php if (!empty($pet['photo_path'])): ?>
                                        <img
                                            class="admin-customer-pet-photo"
                                            src="../customer/pet-media.php?pet_id=<?= (int) $pet['pet_id'] ?>&amp;type=photo"
                                            alt="<?= auth_escape((string) $pet['pet_name']) ?>"
                                            loading="lazy"
                                        >
                                    <?php else: ?>
                                        <div class="admin-customer-pet-photo admin-customer-pet-placeholder" aria-hidden="true">🐾</div>
                                    <?php endif; ?>
                                    <div class="admin-customer-pet-content">
                                        <span class="admin-customer-pet-kicker"><?= auth_escape((string) $pet['species']) ?></span>
                                        <h3><?= auth_escape((string) $pet['pet_name']) ?></h3>
                                        <dl class="admin-customer-pet-list">
                                            <?php if (!empty($pet['breed'])): ?>
                                                <div><dt>Breed</dt><dd><?= auth_escape((string) $pet['breed']) ?></dd></div>
                                            <?php endif; ?>
                                            <?php if (!empty($pet['gender'])): ?>
                                                <div><dt>Gender</dt><dd><?= auth_escape((string) $pet['gender']) ?></dd></div>
                                            <?php endif; ?>
                                            <?php if (!empty($pet['birthday'])): ?>
                                                <div><dt>Birthday</dt><dd><?= auth_escape(date('M j, Y', strtotime((string) $pet['birthday']))) ?></dd></div>
                                            <?php elseif ($pet['age'] !== null): ?>
                                                <div><dt>Age</dt><dd><?= (int) $pet['age'] ?> <?= (int) $pet['age'] === 1 ? 'year' : 'years' ?></dd></div>
                                            <?php endif; ?>
                                            <?php if (!empty($pet['color_markings'])): ?>
                                                <div><dt>Color / markings</dt><dd><?= auth_escape((string) $pet['color_markings']) ?></dd></div>
                                            <?php endif; ?>
                                            <?php if ($pet['weight_kg'] !== null): ?>
                                                <div><dt>Weight</dt><dd><?= auth_escape(number_format((float) $pet['weight_kg'], 2)) ?> kg</dd></div>
                                            <?php endif; ?>
                                            <?php if (!empty($pet['breed_size'])): ?>
                                                <div><dt>Breed size</dt><dd><?= auth_escape((string) $pet['breed_size']) ?></dd></div>
                                            <?php endif; ?>
                                        </dl>
                                        <?php if (!empty($pet['notes'])): ?>
                                            <p class="admin-customer-pet-note"><?= nl2br(auth_escape((string) $pet['notes'])) ?></p>
                                        <?php endif; ?>
                                        <?php if (!empty($pet['vaccination_proof_path'])): ?>
                                            <a
                                                class="admin-customer-proof-link"
                                                href="../customer/pet-media.php?pet_id=<?= (int) $pet['pet_id'] ?>&amp;type=vaccination"
                                                target="_blank"
                                                rel="noopener"
                                            >View vaccination proof ↗</a>
                                        <?php endif; ?>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="admin-panel admin-customer-detail-card" aria-labelledby="appointment-history-title">
                    <div class="admin-panel-heading">
                        <div>
                            <span class="eyebrow">Past and upcoming bookings</span>
                            <h2 id="appointment-history-title">Appointment History</h2>
                        </div>
                        <span class="admin-panel-count"><?= count($appointments) ?></span>
                    </div>

                    <?php if (!$appointments): ?>
                        <p class="admin-customer-empty">This customer has no appointment history.</p>
                    <?php else: ?>
                        <div class="admin-table-scroll">
                            <table class="admin-table admin-customer-appointments-table">
                                <thead>
                                    <tr>
                                        <th scope="col">Appointment ID</th>
                                        <th scope="col">Pet</th>
                                        <th scope="col">Service</th>
                                        <th scope="col">Date</th>
                                        <th scope="col">Time</th>
                                        <th scope="col">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($appointments as $appointment): ?>
                                        <?php
                                        $status = (string) ($appointment['status'] ?? 'Unknown');
                                        $statusClass = $statusClasses[$status] ?? 'unknown';
                                        ?>
                                        <tr>
                                            <td class="admin-reference">#<?= (int) $appointment['appointment_id'] ?></td>
                                            <td><strong><?= auth_escape((string) $appointment['pet_name']) ?></strong></td>
                                            <td><?= auth_escape((string) $appointment['service_display_name']) ?></td>
                                            <td><?= auth_escape(date('M j, Y', strtotime((string) $appointment['appointment_date']))) ?></td>
                                            <td><?= auth_escape(date('g:i A', strtotime((string) $appointment['appointment_time']))) ?></td>
                                            <td>
                                                <span class="customer-status customer-status--<?= auth_escape($statusClass) ?>">
                                                    <?= auth_escape($status) ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="admin-panel admin-customer-detail-card" aria-labelledby="grooming-history-title">
                    <div class="admin-panel-heading">
                        <div>
                            <span class="eyebrow">Completed care</span>
                            <h2 id="grooming-history-title">Grooming History</h2>
                        </div>
                        <span class="admin-panel-count"><?= count($groomingHistory) ?></span>
                    </div>
                    <?php if (!$groomingHistory): ?>
                        <p class="admin-customer-empty">This customer has no grooming history.</p>
                    <?php else: ?>
                        <div class="admin-table-scroll">
                            <table class="admin-table admin-customer-history-table">
                                <thead>
                                    <tr>
                                        <th scope="col">Date</th>
                                        <th scope="col">Pet</th>
                                        <th scope="col">Service</th>
                                        <th scope="col">Groomer</th>
                                        <th scope="col">Notes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($groomingHistory as $record): ?>
                                        <?php $groomerName = trim((string) ($record['groomer_first_name'] ?? '') . ' ' . (string) ($record['groomer_last_name'] ?? '')); ?>
                                        <tr>
                                            <td><?= auth_escape(date('M j, Y', strtotime((string) $record['grooming_date']))) ?></td>
                                            <td><strong><?= auth_escape((string) $record['pet_name']) ?></strong></td>
                                            <td><?= auth_escape((string) $record['service_display_name']) ?></td>
                                            <td><?= auth_escape($groomerName ?: 'Not assigned') ?></td>
                                            <td><?= auth_escape(trim((string) ($record['grooming_notes'] ?? '')) ?: '—') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="admin-panel admin-customer-detail-card" aria-labelledby="customer-reviews-title">
                    <div class="admin-panel-heading">
                        <div>
                            <span class="eyebrow">Customer feedback</span>
                            <h2 id="customer-reviews-title">Submitted Reviews</h2>
                        </div>
                        <span class="admin-panel-count"><?= count($reviews) ?></span>
                    </div>
                    <?php if (!$reviews): ?>
                        <p class="admin-customer-empty">This customer has not submitted any reviews.</p>
                    <?php else: ?>
                        <div class="admin-customer-review-list">
                            <?php foreach ($reviews as $review): ?>
                                <?php
                                $petName = (string) $review['pet_name'];
                                $petInitial = function_exists('mb_substr')
                                    ? mb_substr($petName, 0, 1, 'UTF-8')
                                    : substr($petName, 0, 1);
                                $rating = max(0, min(5, (int) $review['rating']));
                                $reviewStatus = (string) $review['status'];
                                $reviewStatusClasses = [
                                    'Pending' => 'pending',
                                    'Approved' => 'approved',
                                    'Removed' => 'removed',
                                ];
                                $reviewStatusClass = $reviewStatusClasses[$reviewStatus] ?? 'unknown';
                                $reviewFeedback = trim((string) ($review['feedback'] ?? ''));
                                ?>
                                <article class="admin-customer-review">
                                    <header class="admin-customer-review-header">
                                        <div class="admin-customer-review-pet">
                                            <span class="admin-customer-review-avatar" aria-hidden="true"><?= auth_escape($petInitial ?: '?') ?></span>
                                            <div>
                                                <h3><?= auth_escape($petName) ?></h3>
                                                <div class="admin-customer-review-tags">
                                                    <?php if (!empty($review['service_category'])): ?>
                                                        <span><?= auth_escape((string) $review['service_category']) ?></span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($review['service_name'])): ?>
                                                        <span><?= auth_escape((string) $review['service_name']) ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <span class="admin-customer-review-status is-<?= auth_escape($reviewStatusClass) ?>">
                                            <span class="admin-customer-review-status-dot" aria-hidden="true"></span>
                                            <?= auth_escape($reviewStatus) ?>
                                        </span>
                                    </header>
                                    <div class="admin-customer-review-rating">
                                        <span aria-label="<?= $rating ?> out of 5 stars"><?= str_repeat('★', $rating) ?><span><?= str_repeat('☆', 5 - $rating) ?></span></span>
                                        <strong><?= $rating ?>/5<?= $rating === 5 ? ' · Loved it' : '' ?></strong>
                                    </div>
                                    <p class="admin-customer-review-feedback"><?= nl2br(auth_escape($reviewFeedback !== '' ? $reviewFeedback : 'No written feedback submitted.')) ?></p>
                                    <footer class="admin-customer-review-footer">
                                        <span class="admin-customer-review-date">
                                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                                <rect x="3" y="5" width="18" height="16" rx="2"></rect>
                                                <path d="M16 3v4M8 3v4M3 10h18"></path>
                                            </svg>
                                            <span><?= auth_escape(date('M j, Y', strtotime((string) $review['appointment_date']))) ?> · Appointment #<?= (int) $review['appointment_id'] ?></span>
                                        </span>
                                        <a class="admin-customer-review-details" href="reviews.php">View Details</a>
                                    </footer>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            </main>
        </div>
    </div>
</body>
</html>
