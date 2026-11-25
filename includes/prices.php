<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/grooming_price_data.php';

$priceLoadError = false;
try {
    $groomingServices = grooming_get_available_services($conn);
    $groomingServicesBySpecies = grooming_group_services_by_species($groomingServices);
} catch (mysqli_sql_exception $exception) {
    error_log('Pawsome public pricelist query failed: ' . $exception->getMessage());
    $groomingServicesBySpecies = ['Dog' => [], 'Cat' => [], 'Other' => []];
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
        <?php $speciesLabels = ['Dog' => 'Dog', 'Cat' => 'Cat', 'Other' => 'Other Small Animal']; ?>
        <div class="prices-tabs" role="tablist" aria-label="Grooming price categories">
            <?php foreach ($speciesLabels as $species => $label): ?>
                <?php $isActive = $species === 'Dog'; ?>
                <button
                    class="prices-tab<?= $isActive ? ' is-active' : '' ?>"
                    id="<?= htmlspecialchars(strtolower($species), ENT_QUOTES, 'UTF-8') ?>-prices-tab"
                    type="button"
                    role="tab"
                    aria-selected="<?= $isActive ? 'true' : 'false' ?>"
                    aria-controls="<?= htmlspecialchars(strtolower($species), ENT_QUOTES, 'UTF-8') ?>-prices-panel"
                    tabindex="<?= $isActive ? '0' : '-1' ?>"
                ><?= htmlspecialchars(strtoupper($label), ENT_QUOTES, 'UTF-8') ?> GROOMING PRICES</button>
            <?php endforeach; ?>
        </div>
        <?php foreach ($speciesLabels as $species => $label): ?>
            <?php $speciesKey = strtolower($species); ?>
            <?php $isActive = $species === 'Dog'; ?>
            <div
                class="prices-panel"
                id="<?= htmlspecialchars($speciesKey, ENT_QUOTES, 'UTF-8') ?>-prices-panel"
                role="tabpanel"
                aria-labelledby="<?= htmlspecialchars($speciesKey, ENT_QUOTES, 'UTF-8') ?>-prices-tab"
                tabindex="0"
                <?= $isActive ? '' : 'hidden' ?>
            >
                <?php if (!$priceLoadError && !$groomingServicesBySpecies[$species]): ?>
                    <p class="prices-note">No available <?= htmlspecialchars(strtolower($label), ENT_QUOTES, 'UTF-8') ?> grooming services are listed right now.</p>
                <?php endif; ?>
                <?php foreach ($groomingServicesBySpecies[$species] as $categoryName => $categoryServices): ?>
                    <section class="prices-category-table" aria-label="<?= htmlspecialchars($label . ' ' . $categoryName, ENT_QUOTES, 'UTF-8') ?>">
                        <h3><?= htmlspecialchars((string) $categoryName, ENT_QUOTES, 'UTF-8') ?></h3>
                        <div class="prices-table-wrap">
                            <table class="prices-table">
                                <thead>
                                    <tr>
                                        <th scope="col">Service</th>
                                        <th scope="col">Duration</th>
                                        <th scope="col">Price</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($categoryServices as $service): ?>
                                        <tr>
                                            <td>
                                                <?= htmlspecialchars((string) $service['service_name'], ENT_QUOTES, 'UTF-8') ?>
                                                <?= $service['pet_size'] !== 'Any' ? ' (' . htmlspecialchars((string) $service['pet_size'], ENT_QUOTES, 'UTF-8') . ')' : '' ?>
                                            </td>
                                            <td><?= (int) $service['duration'] ?> min</td>
                                            <td><?= htmlspecialchars(grooming_format_service_price($service), ENT_QUOTES, 'UTF-8') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
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
