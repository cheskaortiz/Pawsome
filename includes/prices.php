<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/grooming_price_data.php';

$priceLoadError = false;
try {
    $groomingServices = grooming_get_available_services($conn);
    $groomingServicesBySpecies = grooming_group_services_by_species($groomingServices);
} catch (mysqli_sql_exception $exception) {
    error_log('Pawsome public pricelist query failed: ' . $exception->getMessage());
    $groomingServicesBySpecies = ['Dog' => [], 'Cat' => []];
    $priceLoadError = true;
}
?>
<section class="prices-section section-padding" id="prices">
    <div class="container prices-container">
        <div class="section-heading">
            <h2>Pawsome Grooming Prices</h2>
            <p>Explore available grooming services and prices for your furry companions.</p>
        </div>
        <?php if ($priceLoadError): ?>
            <p class="prices-note" role="alert">We couldn't load grooming prices right now. Please try again later.</p>
        <?php endif; ?>
        <div class="prices-tabs" role="tablist" aria-label="Grooming price categories">
            <button class="prices-tab is-active" id="dog-prices-tab" type="button" role="tab" aria-selected="true" aria-controls="dog-prices-panel" tabindex="0">DOG GROOMING PRICES</button>
            <button class="prices-tab" id="cat-prices-tab" type="button" role="tab" aria-selected="false" aria-controls="cat-prices-panel" tabindex="-1">CAT GROOMING PRICES</button>
        </div>
        <div class="prices-panel" id="dog-prices-panel" role="tabpanel" aria-labelledby="dog-prices-tab" tabindex="0">
            <div class="prices-table-wrap">
                <table class="prices-table">
                    <thead>
                        <tr>
                            <th scope="col">Category</th>
                            <th scope="col">Service</th>
                            <th scope="col">PHP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($groomingServicesBySpecies['Dog'] as $categoryName => $categoryServices): ?>
                            <?php foreach ($categoryServices as $service): ?>
                            <tr>
                                <td><?= htmlspecialchars((string) $categoryName, ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string) $service['service_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td>₱<?= htmlspecialchars(number_format((float) $service['price'], 2), ENT_QUOTES, 'UTF-8') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                        <?php if (!$priceLoadError && !$groomingServicesBySpecies['Dog']): ?>
                            <tr><td colspan="3">No available dog grooming services are listed right now.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="prices-panel" id="cat-prices-panel" role="tabpanel" aria-labelledby="cat-prices-tab" tabindex="0" hidden>
            <div class="prices-table-wrap">
                <table class="prices-table">
                    <thead>
                        <tr>
                            <th scope="col">Category</th>
                            <th scope="col">Service</th>
                            <th scope="col">PHP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($groomingServicesBySpecies['Cat'] as $categoryName => $categoryServices): ?>
                            <?php foreach ($categoryServices as $service): ?>
                            <tr>
                                <td><?= htmlspecialchars((string) $categoryName, ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string) $service['service_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td>₱<?= htmlspecialchars(number_format((float) $service['price'], 2), ENT_QUOTES, 'UTF-8') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                        <?php if (!$priceLoadError && !$groomingServicesBySpecies['Cat']): ?>
                            <tr><td colspan="3">No available cat grooming services are listed right now.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <p class="prices-note">Prices and service details are read from Pawsome's available service records.</p>
    </div>
</section>
<script>
    (() => {
        const tabs = Array.from(document.querySelectorAll('#prices [role="tab"]'));

        const activateTab = (selectedTab, focusTab = false) => {
            tabs.forEach((tab) => {
                const isSelected = tab === selectedTab;
                tab.setAttribute('aria-selected', String(isSelected));
                tab.tabIndex = isSelected ? 0 : -1;
                tab.classList.toggle('is-active', isSelected);
                document.getElementById(tab.getAttribute('aria-controls')).hidden = !isSelected;
            });

            if (focusTab) {
                selectedTab.focus();
            }
        };

        tabs.forEach((tab, index) => {
            tab.addEventListener('click', () => activateTab(tab));
            tab.addEventListener('keydown', (event) => {
                let nextIndex;

                if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
                    nextIndex = (index + 1) % tabs.length;
                } else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
                    nextIndex = (index - 1 + tabs.length) % tabs.length;
                } else if (event.key === 'Home') {
                    nextIndex = 0;
                } else if (event.key === 'End') {
                    nextIndex = tabs.length - 1;
                }

                if (nextIndex !== undefined) {
                    event.preventDefault();
                    activateTab(tabs[nextIndex], true);
                }
            });
        });
    })();
</script>
