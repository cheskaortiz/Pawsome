<?php
$dogGroomingPrices = [
    ['Bath and Blow Dry', '₱XXX'],
    ['Haircut and Styling', '₱XXX'],
    ['Nail Trimming', '₱XXX'],
    ['Ear Cleaning', '₱XXX'],
    ['Full Grooming', '₱XXX'],
];
$catGroomingPrices = [
    ['Bath and Blow Dry', '₱XXX'],
    ['Haircut and Styling', '₱XXX'],
    ['Nail Trimming', '₱XXX'],
    ['Ear Cleaning', '₱XXX'],
    ['Full Grooming', '₱XXX'],
];
?>
<section class="prices-section section-padding" id="prices">
    <div class="container prices-container">
        <div class="section-heading">
            <h2>Pawsome Grooming Prices</h2>
            <p>Explore our grooming services and prices for your furry companions.</p>
        </div>
        <div class="prices-tabs" role="tablist" aria-label="Grooming price categories">
            <button class="prices-tab is-active" id="dog-prices-tab" type="button" role="tab" aria-selected="true" aria-controls="dog-prices-panel" tabindex="0">DOG GROOMING PRICES</button>
            <button class="prices-tab" id="cat-prices-tab" type="button" role="tab" aria-selected="false" aria-controls="cat-prices-panel" tabindex="-1">CAT GROOMING PRICES</button>
        </div>
        <div class="prices-panel" id="dog-prices-panel" role="tabpanel" aria-labelledby="dog-prices-tab" tabindex="0">
            <div class="prices-table-wrap">
                <table class="prices-table">
                    <thead>
                        <tr>
                            <th scope="col">Service</th>
                            <th scope="col">PHP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($dogGroomingPrices as [$service, $price]): ?>
                            <tr>
                                <td><?= htmlspecialchars($service, ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($price, ENT_QUOTES, 'UTF-8') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="prices-panel" id="cat-prices-panel" role="tabpanel" aria-labelledby="cat-prices-tab" tabindex="0" hidden>
            <div class="prices-table-wrap">
                <table class="prices-table">
                    <thead>
                        <tr>
                            <th scope="col">Service</th>
                            <th scope="col">PHP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($catGroomingPrices as [$service, $price]): ?>
                            <tr>
                                <td><?= htmlspecialchars($service, ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($price, ENT_QUOTES, 'UTF-8') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <p class="prices-note">Prices are subject to change. Please confirm the final grooming price when booking.</p>
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
