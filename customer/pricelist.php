<?php
require_once __DIR__ . '/../includes/auth.php';
auth_require_customer();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/grooming_price_data.php';

$pageTitle = 'Pricelist';
$activeCustomerPage = 'pricelist';
$services = [];
$servicesBySpecies = ['Dog' => [], 'Cat' => [], 'Other' => []];
$loadError = false;

try {
    $services = grooming_get_available_services($conn);
    $servicesBySpecies = grooming_group_services_by_species($services);
} catch (mysqli_sql_exception $exception) {
    error_log('Pawsome customer pricelist query failed: ' . $exception->getMessage());
    $loadError = true;
}

$renderCustomerPriceTable = static function (array $services): void {
    ?>
    <div class="customer-price-table-wrap">
        <table class="customer-price-table">
            <thead>
                <tr>
                    <th scope="col">Service</th>
                    <th scope="col">Duration</th>
                    <th scope="col">Price</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($services as $service): ?>
                    <tr>
                        <td>
                            <?= auth_escape((string) $service['service_name']) ?>
                            <?php if ($service['pet_size'] !== 'Any'): ?>
                                <span class="customer-price-size">(<?= auth_escape((string) $service['pet_size']) ?>)</span>
                            <?php endif; ?>
                        </td>
                        <td><?= (int) $service['duration'] ?> min</td>
                        <td><?= auth_escape(grooming_format_service_price($service)) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
};

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
    <section class="customer-pricelist-species" aria-labelledby="pricelist-species-<?= auth_escape(strtolower($species)) ?>">
        <div class="customer-section-heading">
            <h2 id="pricelist-species-<?= auth_escape(strtolower($species)) ?>"><?= auth_escape($species === 'Other' ? 'Other Small Animal' : $species) ?> Grooming Prices</h2>
        </div>
        <?php if (!$categories && !$loadError): ?>
            <p class="customer-info" role="status">No available <?= auth_escape(strtolower($species)) ?> grooming services are listed right now.</p>
        <?php endif; ?>
        <div class="customer-pricelist-grid">
        <?php foreach ($categories as $categoryName => $categoryServices): ?>
            <?php
            $isFlatRate = stripos($categoryName, 'flat rate') !== false;
            $isSizeDependent = stripos($categoryName, 'size-dependent') !== false;
            $isGroomCategory = preg_match('/^(basic|premium)\s+groom$/i', (string) $categoryName) === 1;
            $categoryClass = 'customer-pricelist-category';
            if ($isFlatRate) {
                $categoryClass .= ' customer-pricelist-category--flat-rate';
            } elseif ($isSizeDependent) {
                $categoryClass .= ' customer-pricelist-category--size-dependent';
            } elseif ($isGroomCategory) {
                $categoryClass .= ' customer-pricelist-category--groom';
                $categoryClass .= stripos($categoryName, 'basic') !== false
                    ? ' customer-pricelist-category--basic-groom'
                    : ' customer-pricelist-category--premium-groom';
            }
            ?>
            <section class="<?= auth_escape($categoryClass) ?>" aria-label="<?= auth_escape($species . ' ' . $categoryName) ?>">
                <h3><?= auth_escape($categoryName) ?></h3>
                <?php if ($isFlatRate): ?>
                    <?php $columnSize = max(1, (int) ceil(count($categoryServices) / 2)); ?>
                    <div class="customer-price-flat-grid">
                        <?php foreach (array_chunk($categoryServices, $columnSize) as $serviceColumn): ?>
                            <?php $renderCustomerPriceTable($serviceColumn); ?>
                        <?php endforeach; ?>
                    </div>
                <?php elseif ($isSizeDependent): ?>
                    <?php
                    $servicesByName = [];
                    foreach ($categoryServices as $service) {
                        $servicesByName[(string) $service['service_name']][] = $service;
                    }
                    ?>
                    <div class="customer-price-size-grid">
                        <?php foreach ($servicesByName as $serviceName => $serviceGroup): ?>
                            <section class="customer-price-size-card" aria-label="<?= auth_escape((string) $serviceName) ?>">
                                <h4><?= auth_escape((string) $serviceName) ?></h4>
                                <?php $renderCustomerPriceTable($serviceGroup); ?>
                            </section>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <?php $renderCustomerPriceTable($categoryServices); ?>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
        </div>
    </section>
<?php endforeach; ?>

<p class="customer-muted customer-price-note">Prices and service details are read from Pawsome's available service records.</p>
<?php require __DIR__ . '/../includes/customer_footer.php'; ?>
