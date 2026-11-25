<?php
require_once __DIR__ . '/../includes/auth.php';
auth_require_customer();

$pageTitle = 'Home';
$activeCustomerPage = 'home';
require __DIR__ . '/../includes/customer_header.php';
?>
<section class="customer-welcome">
    <span class="eyebrow">Your Pawsome account</span>
    <h1>Welcome back, <span><?= auth_escape((string) ($_SESSION['first_name'] ?? 'pet parent')) ?></span>!</h1>
    <p>Keep your pet's grooming plans close at paw. Check their records, stay on top of appointments, and find the right care for them.</p>
    <a class="button button-primary customer-book-button" href="appointments.php">
        Book Now <span aria-hidden="true">→</span>
    </a>
    <p class="customer-book-note">Online booking isn't available yet. This button opens your appointment list.</p>
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
            <p>See the pet details saved to your account.</p>
            <span class="customer-card-link">View my pets <span aria-hidden="true">→</span></span>
        </a>
        <a class="customer-shortcut-card" href="appointments.php">
            <span class="customer-shortcut-icon" aria-hidden="true">02</span>
            <h3>Appointments</h3>
            <p>Review your grooming appointment history and status.</p>
            <span class="customer-card-link">View appointments <span aria-hidden="true">→</span></span>
        </a>
        <a class="customer-shortcut-card" href="pricelist.php">
            <span class="customer-shortcut-icon" aria-hidden="true">03</span>
            <h3>Pricelist</h3>
            <p>Explore currently available grooming services.</p>
            <span class="customer-card-link">Explore services <span aria-hidden="true">→</span></span>
        </a>
    </div>
</section>
<?php require __DIR__ . '/../includes/customer_footer.php'; ?>
