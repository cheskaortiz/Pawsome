<?php
require_once __DIR__ . '/../includes/auth.php';
auth_require_customer();
require_once __DIR__ . '/../config/database.php';

$pageTitle = 'My Pets';
$activeCustomerPage = 'pets';
$customerId = (int) $_SESSION['user_id'];
$pets = [];
$loadError = false;
$successMessage = auth_take_flash('pet_success');
$errorMessage = auth_take_flash('pet_error');

try {
    $statement = $conn->prepare(
        'SELECT pet_id, pet_name, species, breed, gender, age, notes, birthday,
                photo_path, vaccination_proof_path, color_markings, weight_kg, breed_size
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
<section class="customer-page-heading pet-page-heading">
    <div>
        <span class="eyebrow">Your furry family</span>
        <h1>My <span>Pets</span></h1>
        <p>Keep each pet's care details together in one place.</p>
    </div>
    <a class="button button-primary pet-add-button" href="pet-form.php">
        <span aria-hidden="true">+</span> Add Pet
    </a>
</section>

<?php if ($successMessage !== null): ?>
    <p class="customer-success" role="status"><?= auth_escape($successMessage) ?></p>
<?php endif; ?>
<?php if ($errorMessage !== null): ?>
    <p class="customer-alert" role="alert"><?= auth_escape($errorMessage) ?></p>
<?php endif; ?>

<?php if ($loadError): ?>
    <p class="customer-alert" role="alert">We couldn't load your pets right now. Please try again later.</p>
<?php elseif (!$pets): ?>
    <section class="customer-empty-state pet-empty-state">
        <span class="customer-empty-mark" aria-hidden="true">🐾</span>
        <h2>No pet profiles yet</h2>
        <p>Add your first pet to keep their grooming details and documents close at paw.</p>
        <a class="button button-primary" href="pet-form.php"><span aria-hidden="true">+</span> Add your first pet</a>
    </section>
<?php else: ?>
    <div class="customer-pet-grid pet-profile-grid">
        <?php foreach ($pets as $pet): ?>
            <article class="customer-data-card pet-profile-card" id="pet-profile-<?= (int) $pet['pet_id'] ?>">
                <?php if (!empty($pet['photo_path'])): ?>
                    <img
                        class="pet-profile-photo"
                        src="pet-media.php?pet_id=<?= (int) $pet['pet_id'] ?>&amp;type=photo"
                        alt="<?= auth_escape((string) $pet['pet_name']) ?> the <?= auth_escape((string) $pet['species']) ?>"
                        loading="lazy"
                    >
                <?php else: ?>
                    <div class="pet-profile-photo pet-photo-placeholder" aria-hidden="true">🐾</div>
                <?php endif; ?>

                <div class="pet-profile-card-content">
                    <span class="customer-card-kicker"><?= auth_escape((string) $pet['species']) ?></span>
                    <h2><?= auth_escape((string) $pet['pet_name']) ?></h2>
                    <dl class="customer-detail-list">
                        <?php if (!empty($pet['breed'])): ?>
                            <div><dt>Breed</dt><dd><?= auth_escape((string) $pet['breed']) ?></dd></div>
                        <?php endif; ?>
                        <div><dt>Gender</dt><dd><?= auth_escape((string) $pet['gender']) ?></dd></div>
                        <?php if (!empty($pet['birthday'])): ?>
                            <div>
                                <dt>Birthday</dt>
                                <dd><?= auth_escape(date('M j, Y', strtotime((string) $pet['birthday']))) ?></dd>
                            </div>
                        <?php endif; ?>
                        <?php if ($pet['age'] !== null): ?>
                            <div>
                                <dt>Age</dt>
                                <dd><?= (int) $pet['age'] === 0 ? 'Under 1 year' : auth_escape((string) $pet['age']) . ' ' . ((int) $pet['age'] === 1 ? 'year' : 'years') ?></dd>
                            </div>
                        <?php endif; ?>
                        <?php if ($pet['color_markings'] !== null && $pet['color_markings'] !== ''): ?>
                            <div><dt>Color / markings</dt><dd><?= auth_escape((string) $pet['color_markings']) ?></dd></div>
                        <?php endif; ?>
                        <?php if ($pet['weight_kg'] !== null): ?>
                            <div><dt>Weight</dt><dd><?= auth_escape(number_format((float) $pet['weight_kg'], 2)) ?> kg</dd></div>
                        <?php endif; ?>
                        <?php if (!empty($pet['breed_size'])): ?>
                            <div><dt>Breed size</dt><dd><?= auth_escape((string) $pet['breed_size']) ?></dd></div>
                        <?php endif; ?>
                    </dl>

                    <?php if (!empty($pet['vaccination_proof_path'])): ?>
                        <a class="pet-proof-link" href="pet-media.php?pet_id=<?= (int) $pet['pet_id'] ?>&amp;type=vaccination" target="_blank" rel="noopener">
                            View vaccination proof <span aria-hidden="true">↗</span>
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($pet['notes'])): ?>
                        <p class="customer-card-note"><?= nl2br(auth_escape((string) $pet['notes'])) ?></p>
                    <?php endif; ?>

                    <div class="pet-card-actions">
                        <a class="button button-primary button-small" href="pet-form.php?pet_id=<?= (int) $pet['pet_id'] ?>">Edit details</a>
                        <form method="post" action="pet-delete.php" onsubmit="return confirm('Delete this pet profile? This cannot be undone.');">
                            <input type="hidden" name="csrf_token" value="<?= auth_escape(auth_csrf_token()) ?>">
                            <input type="hidden" name="pet_id" value="<?= (int) $pet['pet_id'] ?>">
                            <button class="pet-delete-button" type="submit">Delete</button>
                        </form>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/customer_footer.php'; ?>
