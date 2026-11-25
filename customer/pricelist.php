<?php
require_once __DIR__ . '/../includes/auth.php';
auth_require_customer();
require_once __DIR__ . '/../config/database.php';

$pageTitle = 'Pricelist';
$activeCustomerPage = 'pricelist';
$services = [];
$loadError = false;

try {
    $result = $conn->query(
        "SELECT service_name, description, price, duration
         FROM services
         WHERE status = 'Available'
         ORDER BY service_name"
    );
    $services = $result->fetch_all(MYSQLI_ASSOC);
    $result->free();
} catch (mysqli_sql_exception $exception) {
    error_log('Pawsome customer pricelist query failed: ' . $exception->getMessage());
    $loadError = true;
}

require __DIR__ . '/../includes/customer_header.php';
?>
<section class="customer-page-heading">
    <span class="eyebrow">A little something for every pet</span>
    <h1>Grooming <span>Pricelist</span></h1>
    <p>Available services and current prices from Pawsome.</p>
</section>

<?php if ($loadError): ?>
    <p class="customer-alert" role="alert">We couldn't load the pricelist right now. Please try again later.</p>
<?php elseif (!$services): ?>
    <section class="customer-empty-state">
        <h2>No services available right now</h2>
        <p>Please check back later for grooming services.</p>
    </section>
<?php else: ?>
    <div class="customer-service-grid">
        <?php foreach ($services as $service): ?>
            <article class="customer-service-card">
                <span class="customer-service-icon" aria-hidden="true">✦</span>
                <h2><?= auth_escape((string) $service['service_name']) ?></h2>
                <?php if (!empty($service['description'])): ?>
                    <p><?= auth_escape((string) $service['description']) ?></p>
                <?php endif; ?>
                <div class="customer-service-meta">
                    <strong>₱<?= auth_escape(number_format((float) $service['price'], 2)) ?></strong>
                    <span><?= auth_escape((string) $service['duration']) ?> min</span>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
    <p class="customer-muted customer-price-note">Prices may change; please confirm the final price when arranging a visit.</p>
<?php endif; ?>
<?php require __DIR__ . '/../includes/customer_footer.php'; ?>
