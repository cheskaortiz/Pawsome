<?php
require_once __DIR__ . '/../includes/auth.php';
auth_require_customer();
require_once __DIR__ . '/../config/database.php';

$pageTitle = 'Home';
$activeCustomerPage = 'home';
$customerId = (int) $_SESSION['user_id'];
$manilaNow = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
$today = $manilaNow->format('Y-m-d');
$currentTime = $manilaNow->format('H:i:s');
$pets = [];
$upcomingAppointments = [];
$petLoadError = false;
$appointmentLoadError = false;

try {
    $statement = $conn->prepare(
        'SELECT pet_id, pet_name, species, breed, photo_path
         FROM pets
         WHERE user_id = ?
         ORDER BY created_at DESC, pet_id DESC'
    );
    $statement->bind_param('i', $customerId);
    $statement->execute();
    $pets = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
} catch (mysqli_sql_exception $exception) {
    error_log('Pawsome customer dashboard pets query failed: ' . $exception->getMessage());
    $petLoadError = true;
}

try {
    $statement = $conn->prepare(
        "SELECT a.appointment_id, a.appointment_date, a.appointment_time, a.status,
                p.pet_id, p.pet_name,
                CASE
                    WHEN s.category IN ('Basic Groom', 'Premium Groom')
                    THEN CONCAT_WS(' - ', s.category, s.service_name)
                    ELSE s.service_name
                END AS service_display_name
         FROM appointments AS a
         INNER JOIN pets AS p ON p.pet_id = a.pet_id AND p.user_id = a.user_id
         INNER JOIN services AS s ON s.service_id = a.service_id
         WHERE a.user_id = ?
           AND (
               a.appointment_date > ?
               OR (a.appointment_date = ? AND a.appointment_time >= ?)
           )
           AND a.status IN ('Pending', 'Confirmed', 'In Progress', 'Ready for Pickup')
         ORDER BY a.appointment_date ASC, a.appointment_time ASC
         LIMIT 3"
    );
    $statement->bind_param('isss', $customerId, $today, $today, $currentTime);
    $statement->execute();
    $upcomingAppointments = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
} catch (mysqli_sql_exception $exception) {
    error_log('Pawsome customer dashboard appointments query failed: ' . $exception->getMessage());
    $appointmentLoadError = true;
}

require __DIR__ . '/../includes/customer_header.php';
?>
<section class="customer-welcome">
    <h1>Welcome back, <span><?= auth_escape((string) ($_SESSION['first_name'] ?? 'pet parent')) ?></span>!</h1>
    <p>Give your pet a little extra care. Choose a grooming service and book a visit in just a few steps.</p>
    <a class="button button-primary customer-book-button" href="booking.php">
        Book Now <span aria-hidden="true">→</span>
    </a>
</section>

<section class="customer-dashboard-section" aria-labelledby="dashboard-pets-title">
    <div class="customer-section-heading customer-dashboard-heading-row">
        <div>
            <span class="eyebrow">Your furry family</span>
            <h2 id="dashboard-pets-title">Your <span>Pets</span></h2>
        </div>
        <?php if (!$petLoadError && $pets): ?>
            <a class="customer-inline-link" href="pets.php">View all <?= count($pets) ?> <?= count($pets) === 1 ? 'pet' : 'pets' ?> <span aria-hidden="true">→</span></a>
        <?php endif; ?>
    </div>
    <?php if ($petLoadError): ?>
        <p class="customer-alert" role="alert">We couldn't load your pets right now. Please try again later.</p>
    <?php elseif (!$pets): ?>
        <section class="customer-empty-state">
            <h3>No pet profiles yet</h3>
            <p>Add a pet profile before starting a grooming booking.</p>
            <a class="button button-primary button-small" href="pet-form.php">Add a Pet</a>
        </section>
    <?php else: ?>
        <div class="customer-dashboard-pet-grid">
            <?php foreach ($pets as $pet): ?>
                <a class="customer-dashboard-pet" href="pets.php#pet-profile-<?= (int) $pet['pet_id'] ?>">
                    <?php if (!empty($pet['photo_path'])): ?>
                        <img
                            src="pet-media.php?pet_id=<?= (int) $pet['pet_id'] ?>&amp;type=photo"
                            alt=""
                            loading="lazy"
                        >
                    <?php else: ?>
                        <span class="customer-dashboard-pet-mark" aria-hidden="true">🐾</span>
                    <?php endif; ?>
                    <div>
                        <h3><?= auth_escape((string) $pet['pet_name']) ?></h3>
                        <p><?= auth_escape((string) $pet['species']) ?><?= !empty($pet['breed']) ? ' · ' . auth_escape((string) $pet['breed']) : '' ?></p>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="customer-dashboard-section" aria-labelledby="dashboard-appointments-title">
    <div class="customer-section-heading customer-dashboard-heading-row">
        <div>
            <span class="eyebrow">What's coming up</span>
            <h2 id="dashboard-appointments-title">Upcoming <span>Appointments</span></h2>
        </div>
        <a class="customer-inline-link" href="appointments.php">All appointments <span aria-hidden="true">→</span></a>
    </div>
    <?php if ($appointmentLoadError): ?>
        <p class="customer-alert" role="alert">We couldn't load upcoming appointments right now. Please try again later.</p>
    <?php elseif (!$upcomingAppointments): ?>
        <section class="customer-empty-state customer-dashboard-empty">
            <h3>No upcoming appointments</h3>
            <p>Your upcoming grooming visits will appear here.</p>
        </section>
    <?php else: ?>
        <div class="customer-upcoming-list">
            <?php foreach ($upcomingAppointments as $appointment): ?>
                <a class="customer-upcoming-card" href="appointments.php">
                    <div>
                        <span class="customer-card-kicker">#<?= (int) $appointment['appointment_id'] ?> · <?= auth_escape((string) $appointment['status']) ?></span>
                        <h3><?= auth_escape((string) $appointment['pet_name']) ?> <span>·</span> <?= auth_escape((string) $appointment['service_display_name']) ?></h3>
                    </div>
                    <p>
                        <?= auth_escape(date('M j, Y', strtotime((string) $appointment['appointment_date']))) ?>
                        <span aria-hidden="true">·</span>
                        <?= auth_escape(date('g:i A', strtotime((string) $appointment['appointment_time']))) ?>
                    </p>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="customer-shortcuts" aria-labelledby="shortcuts-title">
    <div class="customer-section-heading">
        <span class="eyebrow">Your pet-care essentials</span>
        <h2 id="shortcuts-title">Everything in one <span>friendly place</span></h2>
    </div>
    <div class="customer-shortcut-grid">
        <a class="customer-shortcut-card" href="pets.php">
            <span class="customer-shortcut-icon" aria-hidden="true">01</span>
            <h3>My Pets</h3>
            <p>See and update the pet details saved to your account.</p>
            <span class="customer-card-link">View my pets <span aria-hidden="true">→</span></span>
        </a>
        <a class="customer-shortcut-card" href="appointments.php">
            <span class="customer-shortcut-icon" aria-hidden="true">02</span>
            <h3>Appointments</h3>
            <p>Review your grooming appointment history and status.</p>
            <span class="customer-card-link">View appointments <span aria-hidden="true">→</span></span>
        </a>
    </div>
</section>
<?php require __DIR__ . '/../includes/customer_footer.php'; ?>
