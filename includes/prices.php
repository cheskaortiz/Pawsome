<?php
$groomingPrices = [
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
            <span class="eyebrow">Simple and clear</span>
            <h2>Grooming <span>prices</span></h2>
        </div>
        <div class="prices-table-wrap">
            <table class="prices-table">
                <thead>
                    <tr>
                        <th scope="col">Service</th>
                        <th scope="col">Price</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($groomingPrices as [$service, $price]): ?>
                        <tr>
                            <td><?= htmlspecialchars($service, ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($price, ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
