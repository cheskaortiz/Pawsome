<?php
require_once __DIR__ . '/../includes/auth.php';
auth_require_customer();
require_once __DIR__ . '/../config/database.php';

$pageTitle = 'My Pets';
$activeCustomerPage = 'pets';
$customerId = (int) $_SESSION['user_id'];
$pets = [];
$loadError = false;

try {
    $statement = $conn->prepare(
        'SELECT pet_id, pet_name, species, breed, gender, age, notes
         FROM pets
         WHERE user_id = ?
         ORDER BY created_at DESC, pet_id DESC'
    );
    $statement->bind_param('i', $customerId);
    $statement->execute();
    $pets = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
} catch (mysqli_sql_exception $exception) {
    error_log('Pawsome customer pets query failed: ' . $exception->getMessage());
    $loadError = true;
}

require __DIR__ . '/../includes/customer_header.php';
?>
<section class="customer-page-heading">
    <span class="eyebrow">Your furry family</span>
    <h1>My <span>Pets</span></h1>
    <p>Pet profiles saved to your Pawsome account.</p>
</section>

<?php if ($loadError): ?>
    <p class="customer-alert" role="alert">We couldn't load your pets right now. Please try again later.</p>
<?php elseif (!$pets): ?>
    <section class="customer-empty-state">
        <span class="customer-empty-mark" aria-hidden="true">🐾</span>
        <h2>No pet profiles yet</h2>
        <p>There aren't any pets saved to your account yet.</p>
    </section>
<?php else: ?>
    <div class="customer-pet-grid">
        <?php foreach ($pets as $pet): ?>
            <article class="customer-data-card">
                <span class="customer-card-kicker"><?= auth_escape((string) $pet['species']) ?></span>
                <h2><?= auth_escape((string) $pet['pet_name']) ?></h2>
                <dl class="customer-detail-list">
                    <?php if (!empty($pet['breed'])): ?>
                        <div><dt>Breed</dt><dd><?= auth_escape((string) $pet['breed']) ?></dd></div>
                    <?php endif; ?>
                    <div><dt>Gender</dt><dd><?= auth_escape((string) $pet['gender']) ?></dd></div>
                    <?php if ($pet['age'] !== null): ?>
                        <div><dt>Age</dt><dd><?= auth_escape((string) $pet['age']) ?></dd></div>
                    <?php endif; ?>
                </dl>
                <?php if (!empty($pet['notes'])): ?>
                    <p class="customer-card-note"><?= nl2br(auth_escape((string) $pet['notes'])) ?></p>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/customer_footer.php'; ?>
