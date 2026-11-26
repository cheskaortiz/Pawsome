<?php
require_once __DIR__ . '/../includes/auth.php';
auth_require_admin();
require_once __DIR__ . '/../config/database.php';

$activeAdminPage = 'pets';
$adminFirstName = (string) ($_SESSION['first_name'] ?? 'admin');
$rawPetId = $_GET['id'] ?? '';
$petId = is_string($rawPetId)
    ? filter_var($rawPetId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;

if ($petId === false) {
    http_response_code(404);
    exit('Pet not found.');
}

$pet = null;
$appointments = [];
$loadError = false;

try {
    $statement = $conn->prepare(
        'SELECT p.pet_id, p.pet_name, p.species, p.breed, p.gender, p.age, p.birthday,
                p.photo_path, p.vaccination_proof_path, p.color_markings,
                p.weight_kg, p.breed_size, p.notes,
                u.user_id, u.first_name, u.last_name, u.email, u.phone
         FROM pets AS p
         INNER JOIN users AS u ON u.user_id = p.user_id
         WHERE p.pet_id = ? AND u.role = \'customer\'
         LIMIT 1'
    );
    $statement->bind_param('i', $petId);
    $statement->execute();
    $pet = $statement->get_result()->fetch_assoc();
    $statement->close();
} catch (mysqli_sql_exception $exception) {
    error_log('Pawsome admin pet detail query failed: ' . $exception->getMessage());
    $loadError = true;
}

if (!$pet) {
    if (!$loadError) {
        http_response_code(404);
        exit('Pet not found.');
    }
    http_response_code(500);
    exit('We could not load this pet right now.');
}

try {
    $statement = $conn->prepare(
        'SELECT a.appointment_id, a.appointment_date, a.appointment_time, a.status,
                CASE
                    WHEN s.category IN (\'Basic Groom\', \'Premium Groom\')
                    THEN CONCAT_WS(\' - \', s.category, s.service_name)
                    ELSE s.service_name
                END AS service_display_name
         FROM appointments AS a
         INNER JOIN services AS s ON s.service_id = a.service_id
         WHERE a.pet_id = ? AND a.user_id = ?
         ORDER BY a.appointment_date DESC, a.appointment_time DESC, a.appointment_id DESC'
    );
    $customerId = (int) $pet['user_id'];
    $statement->bind_param('ii', $petId, $customerId);
    $statement->execute();
    $appointments = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
} catch (mysqli_sql_exception $exception) {
    error_log('Pawsome admin pet grooming history query failed: ' . $exception->getMessage());
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
];
$hasVaccinationProof = trim((string) ($pet['vaccination_proof_path'] ?? '')) !== '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="View Pawsome pet information.">
    <meta name="theme-color" content="#fbf8ff">
    <title>Pet Details | Pawsome Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800;900&family=Nunito:wght@500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/shared/portal.css">
    <link rel="stylesheet" href="../assets/css/admin/dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin/pets.css">
    <link rel="stylesheet" href="../assets/css/admin/pet-details.css">
</head>
<body class="customer-app admin-app admin-pet-details-page">
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

            <main class="customer-main admin-main admin-pet-detail-main" id="admin-main">
                <a class="admin-pet-back" href="pets.php">&larr; Back to Pets</a>

                <section class="customer-page-heading admin-page-heading admin-pet-detail-heading">
                    <div>
                        <span class="eyebrow">Pet profile</span>
                        <h1><?= auth_escape((string) $pet['pet_name']) ?> <span>#<?= (int) $petId ?></span></h1>
                        <p>Review the pet profile, owner, vaccination document, and grooming history.</p>
                    </div>
                </section>

                <?php if ($loadError): ?>
                    <p class="admin-alert" role="alert">Some pet information could not be loaded. Please try again later.</p>
                <?php endif; ?>

                <div class="admin-pet-detail-top-grid">
                    <section class="admin-panel admin-pet-profile-card" aria-labelledby="pet-profile-title">
                        <div class="admin-panel-heading">
                            <div>
                                <span class="eyebrow">Profile</span>
                                <h2 id="pet-profile-title">Pet Profile</h2>
                            </div>
                        </div>
                        <div class="admin-pet-profile-layout">
                            <?php if (!empty($pet['photo_path'])): ?>
                                <img
                                    class="admin-pet-detail-photo"
                                    src="../customer/pet-media.php?pet_id=<?= (int) $petId ?>&amp;type=photo"
                                    alt="<?= auth_escape((string) $pet['pet_name']) ?>"
                                >
                            <?php else: ?>
                                <div class="admin-pet-detail-photo admin-pet-detail-placeholder" aria-label="No pet photo">🐾</div>
                            <?php endif; ?>
                            <dl class="admin-pet-profile-list">
                                <div><dt>Name</dt><dd><?= auth_escape((string) $pet['pet_name']) ?></dd></div>
                                <div><dt>Species</dt><dd><?= auth_escape((string) $pet['species']) ?></dd></div>
                                <div><dt>Breed</dt><dd><?= auth_escape((string) ($pet['breed'] ?: 'Not provided')) ?></dd></div>
                                <div><dt>Gender</dt><dd><?= auth_escape((string) ($pet['gender'] ?: 'Not provided')) ?></dd></div>
                                <div><dt>Birthday</dt><dd><?= !empty($pet['birthday']) ? auth_escape(date('M j, Y', strtotime((string) $pet['birthday']))) : 'Not provided' ?></dd></div>
                                <div><dt>Age</dt><dd><?php if ($pet['age'] !== null): ?><?= (int) $pet['age'] ?> <?= (int) $pet['age'] === 1 ? 'year' : 'years' ?><?php else: ?>Not provided<?php endif; ?></dd></div>
                                <div><dt>Weight</dt><dd><?= $pet['weight_kg'] !== null ? auth_escape(number_format((float) $pet['weight_kg'], 2)) . ' kg' : 'Not provided' ?></dd></div>
                                <div><dt>Breed size</dt><dd><?= auth_escape((string) ($pet['breed_size'] ?: 'Not provided')) ?></dd></div>
                                <div><dt>Color / markings</dt><dd><?= auth_escape((string) ($pet['color_markings'] ?: 'Not provided')) ?></dd></div>
                            </dl>
                        </div>
                    </section>

                    <section class="admin-panel admin-pet-owner-card" aria-labelledby="owner-information-title">
                        <div class="admin-panel-heading">
                            <div>
                                <span class="eyebrow">Owner</span>
                                <h2 id="owner-information-title">Owner Information</h2>
                            </div>
                        </div>
                        <dl class="admin-pet-owner-list">
                            <div><dt>Customer name</dt><dd><?= auth_escape(trim((string) $pet['first_name'] . ' ' . (string) $pet['last_name'])) ?></dd></div>
                            <div><dt>Email</dt><dd><?= auth_escape((string) $pet['email']) ?></dd></div>
                            <div><dt>Phone</dt><dd><?= auth_escape(trim((string) ($pet['phone'] ?? '')) ?: 'Not provided') ?></dd></div>
                            <div>
                                <dt>Customer profile</dt>
                                <dd><a class="admin-pet-owner-link" href="customer-details.php?id=<?= (int) $pet['user_id'] ?>">View Customer</a></dd>
                            </div>
                        </dl>
                    </section>
                </div>

                <section class="admin-panel admin-pet-detail-card" aria-labelledby="vaccination-information-title">
                    <div class="admin-panel-heading">
                        <div>
                            <span class="eyebrow">Health record</span>
                            <h2 id="vaccination-information-title">Vaccination Information</h2>
                        </div>
                        <span class="admin-vaccination-status <?= $hasVaccinationProof ? 'is-vaccinated' : 'is-not-vaccinated' ?>">
                            <?= $hasVaccinationProof ? 'Proof Uploaded' : 'No Proof Uploaded' ?>
                        </span>
                    </div>
                    <?php if ($hasVaccinationProof): ?>
                        <div class="admin-pet-vaccination-content">
                            <p>A vaccination document is available for this pet.</p>
                            <a
                                class="admin-pet-proof-link"
                                href="../customer/pet-media.php?pet_id=<?= (int) $petId ?>&amp;type=vaccination"
                                target="_blank"
                                rel="noopener"
                            >View uploaded vaccination proof ↗</a>
                        </div>
                    <?php else: ?>
                        <p class="admin-pet-empty">No vaccination proof has been uploaded.</p>
                    <?php endif; ?>
                </section>

                <section class="admin-panel admin-pet-detail-card" aria-labelledby="pet-notes-title">
                    <div class="admin-panel-heading">
                        <div>
                            <span class="eyebrow">Existing profile details</span>
                            <h2 id="pet-notes-title">Pet Profile Notes</h2>
                        </div>
                    </div>
                    <?php if (trim((string) ($pet['notes'] ?? '')) !== ''): ?>
                        <div class="admin-pet-notes"><?= nl2br(auth_escape((string) $pet['notes'])) ?></div>
                    <?php else: ?>
                        <p class="admin-pet-empty">No profile notes are available.</p>
                    <?php endif; ?>
                </section>

                <section class="admin-panel admin-pet-detail-card" aria-labelledby="grooming-history-title">
                    <div class="admin-panel-heading">
                        <div>
                            <span class="eyebrow">Appointments</span>
                            <h2 id="grooming-history-title">Grooming History</h2>
                        </div>
                        <span class="admin-panel-count"><?= count($appointments) ?></span>
                    </div>
                    <?php if (!$appointments): ?>
                        <p class="admin-pet-empty">This pet has no appointment history.</p>
                    <?php else: ?>
                        <div class="admin-table-scroll">
                            <table class="admin-table admin-pet-history-table">
                                <thead>
                                    <tr>
                                        <th scope="col">Appointment ID</th>
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
                                            <td><strong><?= auth_escape((string) $appointment['service_display_name']) ?></strong></td>
                                            <td><?= auth_escape(date('M j, Y', strtotime((string) $appointment['appointment_date']))) ?></td>
                                            <td><?= auth_escape(date('g:i A', strtotime((string) $appointment['appointment_time']))) ?></td>
                                            <td><span class="customer-status customer-status--<?= auth_escape($statusClass) ?>"><?= auth_escape($status) ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>
            </main>
        </div>
    </div>
</body>
</html>
