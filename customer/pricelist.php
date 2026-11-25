<?php
require_once __DIR__ . '/../includes/auth.php';
auth_require_customer();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/grooming_price_data.php';

$pageTitle = 'Pricelist';
$activeCustomerPage = 'pricelist';
$services = [];
$servicesBySpecies = ['Dog' => [], 'Cat' => []];
$loadError = false;

try {
    $services = grooming_get_available_services($conn);
    $servicesBySpecies = grooming_group_services_by_species($services);
} catch (mysqli_sql_exception $exception) {
    error_log('Pawsome customer pricelist query failed: ' . $exception->getMessage());
    $loadError = true;
}

require __DIR__ . '/../includes/customer_header.php';
?>
<section class="customer-page-heading">
    <span class="eyebrow">A little something for every pet</span>
    <h1>Grooming <span>Pricelist</span></h1>
    <p>Browse Pawsome's grooming categories and current service listings.</p>
</section>

<?php if ($loadError): ?>
    <p class="customer-alert" role="alert">We couldn't load the pricelist right now. Please try again later.</p>
<?php endif; ?>

<?php foreach ($servicesBySpecies as $species => $categories): ?>
    <section class="customer-pricelist-category" aria-labelledby="pricelist-species-<?= auth_escape(strtolower($species)) ?>">
        <div class="customer-section-heading">
            <h2 id="pricelist-species-<?= auth_escape(strtolower($species)) ?>"><?= auth_escape($species) ?> Grooming Prices</h2>
        </div>
        <?php if (!$categories && !$loadError): ?>
            <p class="customer-info" role="status">No available <?= auth_escape(strtolower($species)) ?> grooming services are listed right now.</p>
        <?php endif; ?>
        <?php foreach ($categories as $categoryName => $categoryServices): ?>
            <section class="customer-pricelist-category" aria-label="<?= auth_escape($species . ' ' . $categoryName) ?>">
                <div class="customer-section-heading">
                    <h3><?= auth_escape($categoryName) ?></h3>
                </div>
                <div class="customer-service-grid">
                    <?php foreach ($categoryServices as $service): ?>
                        <article class="customer-service-card">
                            <span class="customer-service-icon" aria-hidden="true">✦</span>
                            <h4><?= auth_escape((string) $service['service_name']) ?></h4>
                            <p><?= auth_escape((string) $service['description']) ?></p>
                            <div class="customer-service-meta">
                                <strong>₱<?= auth_escape(number_format((float) $service['price'], 2)) ?></strong>
                                <span><?= (int) $service['duration'] ?> min</span>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endforeach; ?>
    </section>
<?php endforeach; ?>

<p class="customer-muted customer-price-note">Prices and service details are read from Pawsome's available service records.</p>
<?php require __DIR__ . '/../includes/customer_footer.php'; ?>
