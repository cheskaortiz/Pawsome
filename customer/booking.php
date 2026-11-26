<?php
require_once __DIR__ . '/../includes/auth.php';
auth_require_customer();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/customer_booking.php';

$pageTitle = 'Book an Appointment';
$activeCustomerPage = 'appointments';
$customerId = (int) $_SESSION['user_id'];
$errorMessage = auth_take_flash('booking_flow_error') ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!auth_csrf_is_valid($_POST['csrf_token'] ?? null)) {
        $errorMessage = 'Your session expired. Please reload and try again.';
    } else {
        $bookingAction = auth_post_string('booking_action');

        if ($bookingAction === 'select_pet') {
            $petId = filter_var(auth_post_string('pet_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($petId === false) {
                $errorMessage = 'Choose one of your pet profiles to continue.';
            } else {
                try {
                    $statement = $conn->prepare(
                        'SELECT pet_id
                         FROM pets
                         WHERE pet_id = ? AND user_id = ?
                         LIMIT 1'
                    );
                    $statement->bind_param('ii', $petId, $customerId);
                    $statement->execute();
                    $petExists = $statement->get_result()->fetch_assoc();
                    $statement->close();

                    if (!$petExists) {
                        $errorMessage = 'That pet profile could not be found in your account.';
                    } else {
                        if ((int) ($_SESSION['booking_pet_id'] ?? 0) !== $petId) {
                            unset(
                                $_SESSION['booking_service_id'],
                                $_SESSION['booking_date'],
                                $_SESSION['booking_time'],
                                $_SESSION['booking_notes'],
                                $_SESSION['booking_submission_token']
                            );
                        }
                        $_SESSION['booking_pet_id'] = $petId;
                        header('Location: booking.php', true, 303);
                        exit;
                    }
                } catch (mysqli_sql_exception $exception) {
                    error_log('Pawsome booking pet selection failed: ' . $exception->getMessage());
                    $errorMessage = 'We could not select that pet right now. Please try again.';
                }
            }
        } elseif ($bookingAction === 'select_service') {
            $petId = filter_var(auth_post_string('pet_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $serviceId = filter_var(auth_post_string('service_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $sessionPetId = $_SESSION['booking_pet_id'] ?? null;

            if (
                $petId === false
                || $serviceId === false
                || !is_int($sessionPetId)
                || $sessionPetId !== $petId
            ) {
                $errorMessage = 'Select your pet and an available service before continuing.';
            } else {
                try {
                    $statement = $conn->prepare(
                        "SELECT s.service_id
                         FROM services AS s
                         INNER JOIN pets AS p ON p.pet_id = ? AND p.user_id = ?
                         WHERE s.service_id = ?
                           AND s.species = p.species
                           AND (s.pet_size = 'Any' OR s.pet_size = p.breed_size)
                           AND (p.species <> 'Dog' OR p.breed_size IN ('Small', 'Medium', 'Large', 'Giant'))
                           AND s.category IN (
                               'Basic Groom',
                               'Premium Groom',
                               'A La Carte Services (Flat Rate)',
                               'A La Carte Services (Size-Dependent)'
                           )
                           AND s.status = 'Available'
                           AND s.duration > 0
                         LIMIT 1"
                    );
                    $statement->bind_param('iii', $petId, $customerId, $serviceId);
                    $statement->execute();
                    $validSelection = $statement->get_result()->fetch_assoc();
                    $statement->close();

                    if (!$validSelection) {
                        $errorMessage = 'That service is no longer available. Please choose another service.';
                    } else {
                        if ((int) ($_SESSION['booking_service_id'] ?? 0) !== $serviceId) {
                            unset(
                                $_SESSION['booking_date'],
                                $_SESSION['booking_time'],
                                $_SESSION['booking_notes'],
                                $_SESSION['booking_submission_token']
                            );
                        }
                        $_SESSION['booking_service_id'] = $serviceId;
                        header('Location: booking-schedule.php', true, 303);
                        exit;
                    }
                } catch (mysqli_sql_exception $exception) {
                    error_log('Pawsome booking service selection failed: ' . $exception->getMessage());
                    $errorMessage = 'We could not select that service right now. Please try again.';
                }
            }
        } else {
            $errorMessage = 'Choose a pet and service before continuing.';
        }
    }
}

$pets = [];
$services = [];
$loadError = false;
$selectedPet = null;
$selectedService = null;
$selectedPetId = isset($_SESSION['booking_pet_id']) && is_int($_SESSION['booking_pet_id'])
    ? $_SESSION['booking_pet_id']
    : null;
$selectedServiceId = isset($_SESSION['booking_service_id']) && is_int($_SESSION['booking_service_id'])
    ? $_SESSION['booking_service_id']
    : null;

try {
    $statement = $conn->prepare(
        'SELECT pet_id, pet_name, species, breed, breed_size, photo_path
         FROM pets
         WHERE user_id = ?
         ORDER BY created_at DESC, pet_id DESC'
    );
    $statement->bind_param('i', $customerId);
    $statement->execute();
    $pets = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
} catch (mysqli_sql_exception $exception) {
    error_log('Pawsome booking pet list query failed: ' . $exception->getMessage());
    $loadError = true;
}

if (!$loadError && $selectedPetId !== null) {
    foreach ($pets as $pet) {
        if ((int) $pet['pet_id'] === $selectedPetId) {
            $selectedPet = $pet;
            break;
        }
    }

    if ($selectedPet === null) {
        unset($_SESSION['booking_pet_id'], $_SESSION['booking_service_id']);
        $selectedPetId = null;
        $selectedServiceId = null;
    }
}

$serviceLoadError = false;
if (!$loadError && $selectedPet !== null) {
    $petSizeMissing = $selectedPet['species'] === 'Dog'
        && !in_array($selectedPet['breed_size'], ['Small', 'Medium', 'Large', 'Giant'], true);
    try {
        if (!$petSizeMissing && in_array($selectedPet['species'], ['Dog', 'Cat', 'Other'], true)) {
            $statement = $conn->prepare(
                "SELECT s.service_id, s.species, s.pet_size, s.category, s.service_name,
                        s.description, s.price, s.price_note, s.duration, s.status
                 FROM services AS s
                 INNER JOIN pets AS p ON p.pet_id = ? AND p.user_id = ?
                 WHERE s.species = p.species
                   AND (s.pet_size = 'Any' OR s.pet_size = p.breed_size)
                   AND s.category IN (
                       'Basic Groom',
                       'Premium Groom',
                       'A La Carte Services (Flat Rate)',
                       'A La Carte Services (Size-Dependent)'
                   )
                   AND s.duration > 0
                 ORDER BY FIELD(
                     s.category,
                     'Basic Groom',
                     'Premium Groom',
                     'A La Carte Services (Flat Rate)',
                     'A La Carte Services (Size-Dependent)'
                 ), s.service_name, s.service_id"
            );
            $statement->bind_param('ii', $selectedPet['pet_id'], $customerId);
            $statement->execute();
            $services = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
            $statement->close();
        }
    } catch (mysqli_sql_exception $exception) {
        error_log('Pawsome booking service list query failed: ' . $exception->getMessage());
        $serviceLoadError = true;
    }

    foreach ($services as $service) {
        if ((int) $service['service_id'] === $selectedServiceId) {
            $selectedService = $service;
            break;
        }
    }

    if (
        $selectedServiceId !== null
        && ($selectedService === null || $selectedService['status'] !== 'Available')
    ) {
        unset($_SESSION['booking_service_id']);
        $selectedServiceId = null;
        $selectedService = null;
    }
} else {
    $petSizeMissing = false;
}

$serviceCategories = array_fill_keys(customer_booking_service_categories(), []);

foreach ($services as $service) {
    $category = (string) $service['category'];
    if (array_key_exists($category, $serviceCategories)) {
        $serviceCategories[$category][] = $service;
    }
}

require __DIR__ . '/../includes/customer_header.php';
?>
<section class="customer-page-heading">
    <span class="eyebrow">A few steps to a fresher, happier pet</span>
    <h1>Book an <span>Appointment</span></h1>
    <p>Choose a pet first, then select an available grooming service.</p>
</section>

<?php if ($errorMessage !== ''): ?>
    <p class="customer-alert" role="alert"><?= auth_escape($errorMessage) ?></p>
<?php endif; ?>
<?php if ($loadError): ?>
    <p class="customer-alert" role="alert">We couldn't load the pet profiles right now. Please try again later.</p>
<?php elseif (!$pets): ?>
    <section class="customer-empty-state">
        <span class="customer-empty-mark" aria-hidden="true">🐾</span>
        <h2>Add a pet before booking</h2>
        <p>Choose one of your registered pets for their grooming visit.</p>
        <a class="button button-primary" href="pet-form.php">Add a Pet</a>
    </section>
<?php else: ?>
    <section class="booking-step-section" aria-labelledby="booking-pet-title">
        <div class="customer-section-heading">
            <span class="eyebrow">Step 1</span>
            <h2 id="booking-pet-title">Which pet are we grooming?</h2>
        </div>
        <form method="post" action="booking.php" id="booking-pet-form">
            <input type="hidden" name="csrf_token" value="<?= auth_escape(auth_csrf_token()) ?>">
            <input type="hidden" name="booking_action" value="select_pet">
            <div class="booking-pet-grid">
                <?php foreach ($pets as $pet): ?>
                    <label class="booking-pet-option">
                        <input
                            type="radio"
                            name="pet_id"
                            value="<?= (int) $pet['pet_id'] ?>"
                            <?= (int) $pet['pet_id'] === $selectedPetId ? 'checked' : '' ?>
                            required
                        >
                        <span class="booking-pet-card">
                            <?php if (!empty($pet['photo_path'])): ?>
                                <img
                                    src="pet-media.php?pet_id=<?= (int) $pet['pet_id'] ?>&amp;type=photo"
                                    alt=""
                                    loading="lazy"
                                >
                            <?php else: ?>
                                <span class="booking-pet-placeholder" aria-hidden="true">🐾</span>
                            <?php endif; ?>
                            <span class="booking-pet-details">
                                <strong><?= auth_escape((string) $pet['pet_name']) ?></strong>
                                <span><?= auth_escape($pet['species'] === 'Other' ? 'Other Small Animal' : (string) $pet['species']) ?></span>
                                <span><?= !empty($pet['breed']) ? auth_escape((string) $pet['breed']) : 'Breed not provided' ?></span>
                                <?php if (!empty($pet['breed_size'])): ?>
                                    <span><?= auth_escape((string) $pet['breed_size']) ?> size</span>
                                <?php endif; ?>
                            </span>
                            <span class="booking-pet-radio" aria-hidden="true"></span>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
            <div class="booking-step-actions">
                <button class="button button-primary" type="submit">
                    <?= $selectedPet !== null ? 'Use this pet' : 'Continue with this pet' ?>
                    <span aria-hidden="true">→</span>
                </button>
                <a class="customer-inline-link" href="pet-form.php">+ Add another pet</a>
            </div>
        </form>
    </section>

    <?php if ($selectedPet !== null): ?>
        <section class="booking-step-section" aria-labelledby="booking-service-title">
            <div class="customer-section-heading">
                <span class="eyebrow">Step 2 · Booking for <?= auth_escape((string) $selectedPet['pet_name']) ?></span>
                <h2 id="booking-service-title">Choose a grooming service</h2>
            </div>
            <?php if ($serviceLoadError): ?>
                <p class="customer-alert" role="alert">We couldn't load available services right now. Please try again later.</p>
            <?php elseif ($petSizeMissing): ?>
                <p class="customer-info" role="status">
                    Add your dog's registered breed size before choosing a service.
                    <a href="pet-form.php?pet_id=<?= (int) $selectedPet['pet_id'] ?>">Update <?= auth_escape((string) $selectedPet['pet_name']) ?>'s profile</a>.
                </p>
            <?php elseif (!in_array($selectedPet['species'], ['Dog', 'Cat', 'Other'], true)): ?>
                <p class="customer-info" role="status">
                    Update this pet's species in their profile before booking.
                    <a href="pet-form.php?pet_id=<?= (int) $selectedPet['pet_id'] ?>">Update pet profile</a>.
                </p>
            <?php else: ?>
                <?php if (!$services): ?>
                    <p class="customer-info" role="note">There are no grooming services with a valid duration in the database yet.</p>
                <?php endif; ?>
                <p class="booking-selection-summary" id="booking-selection-summary" role="status">
                    <?php if ($selectedService !== null): ?>
                        Selected: <strong><?= auth_escape(customer_booking_service_label($selectedService)) ?></strong>
                        · <?= auth_escape(grooming_format_service_price($selectedService)) ?>
                        · <?= (int) $selectedService['duration'] ?> min
                    <?php else: ?>
                        Select one available service to continue.
                    <?php endif; ?>
                </p>
                <form method="post" action="booking.php" id="booking-service-form">
                    <input type="hidden" name="csrf_token" value="<?= auth_escape(auth_csrf_token()) ?>">
                    <input type="hidden" name="booking_action" value="select_service">
                    <input type="hidden" name="pet_id" value="<?= (int) $selectedPet['pet_id'] ?>">
                <?php $categoryIndex = 0; foreach ($serviceCategories as $categoryName => $categoryServices): ?>
                    <?php $panelId = 'booking-category-' . $categoryIndex; ?>
                    <?php $categoryIndex++; ?>
                    <section class="booking-service-category">
                        <h3>
                            <button
                                class="booking-category-toggle"
                                type="button"
                                aria-expanded="false"
                                aria-controls="<?= auth_escape($panelId) ?>"
                            >
                                <span><?= auth_escape((string) $categoryName) ?></span>
                                <span class="booking-category-count"><?= count($categoryServices) ?></span>
                                <span class="booking-category-chevron" aria-hidden="true">⌄</span>
                            </button>
                        </h3>
                        <div class="booking-category-panel" id="<?= auth_escape($panelId) ?>" aria-hidden="true" inert>
                            <?php if (!$categoryServices): ?>
                                <p class="booking-category-empty">No services are listed in this category for this pet.</p>
                            <?php else: ?>
                                <div class="booking-service-options">
                                    <?php foreach ($categoryServices as $service): ?>
                                        <?php
                                        $serviceAvailable = $service['status'] === 'Available';
                                        $servicePrice = grooming_format_service_price($service);
                                        $serviceDisplayName = customer_booking_service_label($service);
                                        ?>
                                        <article
                                            class="booking-service-option<?= $serviceAvailable ? '' : ' is-unavailable' ?>"
                                            data-service-available="<?= $serviceAvailable ? 'true' : 'false' ?>"
                                            <?= (int) $service['service_id'] === $selectedServiceId ? 'aria-current="true"' : '' ?>
                                        >
                                            <input
                                                class="booking-service-radio"
                                                type="radio"
                                                name="service_id"
                                                value="<?= (int) $service['service_id'] ?>"
                                                data-service-name="<?= auth_escape($serviceDisplayName) ?>"
                                                data-service-price="<?= auth_escape($servicePrice) ?>"
                                                data-service-duration="<?= (int) $service['duration'] ?>"
                                                aria-label="<?= auth_escape($serviceDisplayName . ', ' . $servicePrice . ', ' . (int) $service['duration'] . ' minutes') ?>"
                                                <?= (int) $service['service_id'] === $selectedServiceId ? 'checked' : '' ?>
                                                <?= $serviceAvailable ? 'required' : 'disabled' ?>
                                            >
                                            <div class="booking-service-option-content">
                                                <span class="booking-service-option-heading">
                                                    <strong><?= auth_escape($serviceDisplayName) ?></strong>
                                                    <?php if (!$serviceAvailable): ?>
                                                        <span class="booking-unavailable-tag">Unavailable</span>
                                                    <?php endif; ?>
                                                </span>
                                                <p class="booking-service-description" id="booking-description-<?= (int) $service['service_id'] ?>">
                                                    <?= !empty($service['description']) ? auth_escape((string) $service['description']) : 'Description not provided.' ?>
                                                </p>
                                                <button
                                                    class="booking-read-more"
                                                    type="button"
                                                    aria-expanded="false"
                                                    aria-controls="booking-description-<?= (int) $service['service_id'] ?>"
                                                    aria-label="Read More about <?= auth_escape($serviceDisplayName) ?>"
                                                >Read More</button>
                                                <span class="booking-service-meta">
                                                    <strong><?= auth_escape($servicePrice) ?></strong>
                                                    <span><?= (int) $service['duration'] ?> min</span>
                                                </span>
                                            </div>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </section>
                <?php endforeach; ?>

                <?php if ($services): ?>
                    <div class="booking-step-actions">
                        <button class="button button-primary" id="booking-service-submit" type="submit"<?= $selectedService === null ? ' disabled' : '' ?>>
                            Continue to scheduling <span aria-hidden="true">→</span>
                        </button>
                    </div>
                <?php endif; ?>
                </form>
            <?php endif; ?>
        </section>
    <?php endif; ?>
<?php endif; ?>

<p class="customer-muted customer-price-note">Only services marked Available with a configured duration can be selected. Service duration and price come from Pawsome's records.</p>
<script>
    (() => {
        const categoryButtons = Array.from(document.querySelectorAll('.booking-category-toggle'));

        categoryButtons.forEach((button) => {
            const panel = document.getElementById(button.getAttribute('aria-controls'));
            panel.style.maxHeight = '0px';

            button.addEventListener('click', () => {
                const isExpanded = button.getAttribute('aria-expanded') === 'true';
                button.setAttribute('aria-expanded', String(!isExpanded));
                button.classList.toggle('is-expanded', !isExpanded);
                panel.setAttribute('aria-hidden', String(isExpanded));
                panel.inert = isExpanded;

                if (isExpanded) {
                    panel.style.maxHeight = panel.scrollHeight + 'px';
                    requestAnimationFrame(() => {
                        panel.style.maxHeight = '0px';
                    });
                } else {
                    panel.style.maxHeight = panel.scrollHeight + 'px';
                    panel.addEventListener('transitionend', () => {
                        if (button.getAttribute('aria-expanded') === 'true') {
                            panel.style.maxHeight = 'none';
                        }
                    }, { once: true });
                }
            });
        });

        const serviceRadios = Array.from(document.querySelectorAll('.booking-service-radio'));
        const summary = document.getElementById('booking-selection-summary');
        const submitButton = document.getElementById('booking-service-submit');

        serviceRadios.forEach((radio) => {
            radio.addEventListener('change', () => {
                if (radio.checked && summary) {
                    summary.textContent = 'Selected: ' + radio.dataset.serviceName
                        + ' · ' + radio.dataset.servicePrice
                        + ' · ' + radio.dataset.serviceDuration + ' min';
                    if (submitButton) {
                        submitButton.disabled = false;
                    }
                    document.querySelectorAll('.booking-service-option').forEach((card) => {
                        const selected = card.querySelector('.booking-service-radio').checked;
                        if (selected) {
                            card.setAttribute('aria-current', 'true');
                        } else {
                            card.removeAttribute('aria-current');
                        }
                    });
                }
            });
        });

        document.querySelectorAll('.booking-service-option[data-service-available="true"]').forEach((card) => {
            const selectCard = () => {
                const radio = card.querySelector('.booking-service-radio');
                radio.checked = true;
                radio.dispatchEvent(new Event('change', { bubbles: true }));
            };

            card.addEventListener('click', (event) => {
                if (!event.target.closest('.booking-read-more')) {
                    selectCard();
                }
            });
        });

        document.querySelectorAll('.booking-read-more').forEach((button) => {
            button.addEventListener('click', () => {
                const description = button.parentElement.querySelector('.booking-service-description');
                const expanded = button.getAttribute('aria-expanded') === 'true';
                button.setAttribute('aria-expanded', String(!expanded));
                button.textContent = expanded ? 'Read More' : 'Read Less';
                button.setAttribute(
                    'aria-label',
                    (expanded ? 'Read More about ' : 'Read Less about ')
                        + button.parentElement.querySelector('.booking-service-option-heading strong').textContent
                );
                description.classList.toggle('is-expanded', !expanded);

                const panel = button.closest('.booking-category-panel');
                if (panel && panel.style.maxHeight !== 'none') {
                    panel.style.maxHeight = panel.scrollHeight + 'px';
                }
            });
        });

        const selectedRadio = serviceRadios.find((radio) => radio.checked);
        if (selectedRadio) {
            selectedRadio.closest('.booking-service-option').setAttribute('aria-current', 'true');
        }
    })();
</script>
<?php require __DIR__ . '/../includes/customer_footer.php'; ?>
