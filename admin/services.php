<?php
require_once __DIR__ . '/../includes/auth.php';
auth_require_admin();
require_once __DIR__ . '/../config/database.php';

$adminFirstName = (string) ($_SESSION['first_name'] ?? 'admin');
$activeAdminPage = 'services';
$searchTerm = $_GET['search'] ?? '';
$categoryFilter = $_GET['category'] ?? 'All';
$speciesFilter = $_GET['species'] ?? 'All';
$availabilityFilter = $_GET['availability'] ?? 'All';
$isFragment = ($_GET['fragment'] ?? null) === 'services';
$speciesValues = ['Dog', 'Cat', 'Other'];
$petSizeValues = ['Any', 'Small', 'Medium', 'Large', 'Giant'];
$availabilityValues = ['Available', 'Unavailable'];
$categoryLoadError = false;

$sendJson = static function (array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
};

$serviceCategories = [];
try {
    $categoryStatement = $conn->prepare(
        'SELECT DISTINCT category
         FROM services
         WHERE category IS NOT NULL AND TRIM(category) <> \'\'
         ORDER BY category ASC'
    );
    $categoryStatement->execute();
    $serviceCategories = array_column(
        $categoryStatement->get_result()->fetch_all(MYSQLI_ASSOC),
        'category'
    );
    $categoryStatement->close();
} catch (mysqli_sql_exception $exception) {
    error_log('Pawsome admin service category query failed: ' . $exception->getMessage());
    if ($_SERVER['REQUEST_METHOD'] === 'POST' || $isFragment) {
        $sendJson(['error' => 'We could not load service categories right now. Please try again.'], 500);
    }
    $categoryLoadError = true;
}

$postString = static function (string $key): string {
    return isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : '';
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!auth_csrf_is_valid($_POST['csrf_token'] ?? null)) {
        $sendJson(['error' => 'Your session expired. Reload the page and try again.'], 400);
    }

    $action = $postString('action');
    if (isset($_POST['service_id']) && !is_string($_POST['service_id'])) {
        $sendJson(['error' => 'Choose a valid service.'], 400);
    }
    $rawServiceId = $postString('service_id');
    $serviceId = $rawServiceId === ''
        ? null
        : filter_var($rawServiceId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    if ($action === 'toggle') {
        $newStatus = $postString('status');
        if ($serviceId === false || $serviceId === null || !in_array($newStatus, $availabilityValues, true)) {
            $sendJson(['error' => 'Choose a valid service and availability.'], 400);
        }

        try {
            $statement = $conn->prepare('UPDATE services SET status = ? WHERE service_id = ?');
            $statement->bind_param('si', $newStatus, $serviceId);
            $statement->execute();
            $exists = $statement->affected_rows === 1;
            $statement->close();
            if (!$exists) {
                $statement = $conn->prepare('SELECT service_id FROM services WHERE service_id = ? LIMIT 1');
                $statement->bind_param('i', $serviceId);
                $statement->execute();
                $exists = $statement->get_result()->num_rows === 1;
                $statement->close();
            }
            if (!$exists) {
                $sendJson(['error' => 'That service could not be found.'], 404);
            }
            $sendJson(['success' => true]);
        } catch (mysqli_sql_exception $exception) {
            error_log('Pawsome admin service availability update failed: ' . $exception->getMessage());
            $sendJson(['error' => 'We could not update service availability right now. Please try again.'], 500);
        }
    }

    if ($action !== 'save') {
        $sendJson(['error' => 'Choose a valid service action.'], 400);
    }

    $serviceName = $postString('service_name');
    $description = $postString('description');
    $category = $postString('category');
    $species = $postString('species');
    $petSize = $postString('pet_size');
    $rawPrice = $postString('price');
    $priceNote = $postString('price_note');
    $rawDuration = $postString('duration');
    $duration = filter_var($rawDuration, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    $price = null;
    if ($rawPrice !== '') {
        if (!preg_match('/\A\d{1,8}(?:\.\d{1,2})?\z/D', $rawPrice)) {
            $sendJson(['error' => 'Enter a valid non-negative price with up to two decimal places.'], 400);
        }
        $price = number_format((float) $rawPrice, 2, '.', '');
    }
    if (
        ($serviceId === null && $rawServiceId !== '')
        || $serviceId === false
        || $serviceName === ''
        || auth_string_length($serviceName) > 100
        || auth_string_length($description) > 10000
        || !in_array($category, $serviceCategories, true)
        || !in_array($species, $speciesValues, true)
        || !in_array($petSize, $petSizeValues, true)
        || auth_string_length($priceNote) > 50
        || $duration === false
    ) {
        $sendJson(['error' => 'Check the service details and try again.'], 400);
    }

    $descriptionValue = $description === '' ? null : $description;
    $priceNoteValue = $priceNote === '' ? null : $priceNote;

    try {
        if ($serviceId === null) {
            $statement = $conn->prepare(
                "INSERT INTO services
                    (species, pet_size, category, service_name, description, price, price_note, duration, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Available')"
            );
            $statement->bind_param(
                'sssssssi',
                $species,
                $petSize,
                $category,
                $serviceName,
                $descriptionValue,
                $price,
                $priceNoteValue,
                $duration
            );
            $statement->execute();
            $statement->close();
        } else {
            $statement = $conn->prepare(
                'UPDATE services
                 SET species = ?, pet_size = ?, category = ?, service_name = ?,
                     description = ?, price = ?, price_note = ?, duration = ?
                 WHERE service_id = ?'
            );
            $statement->bind_param(
                'sssssssii',
                $species,
                $petSize,
                $category,
                $serviceName,
                $descriptionValue,
                $price,
                $priceNoteValue,
                $duration,
                $serviceId
            );
            $statement->execute();
            if ($statement->affected_rows === 0) {
                $statement->close();
                $check = $conn->prepare('SELECT service_id FROM services WHERE service_id = ? LIMIT 1');
                $check->bind_param('i', $serviceId);
                $check->execute();
                $exists = $check->get_result()->num_rows === 1;
                $check->close();
                if (!$exists) {
                    $sendJson(['error' => 'That service could not be found.'], 404);
                }
            } else {
                $statement->close();
            }
        }
        $sendJson(['success' => true]);
    } catch (mysqli_sql_exception $exception) {
        error_log('Pawsome admin service save failed: ' . $exception->getMessage());
        $sendJson([
            'error' => $exception->getCode() === 1062
                ? 'A service with the same species, category, name, and size already exists.'
                : 'We could not save this service right now. Please try again.',
        ], $exception->getCode() === 1062 ? 409 : 500);
    }
}

if (
    !is_string($searchTerm)
    || auth_string_length(trim($searchTerm)) > 100
    || !is_string($categoryFilter)
    || !is_string($speciesFilter)
    || !is_string($availabilityFilter)
) {
    if ($isFragment) {
        $sendJson(['error' => 'Choose valid service filters.'], 400);
    }
    http_response_code(400);
    exit('Choose valid service filters.');
}
$searchTerm = trim($searchTerm);
if (
    ($categoryFilter !== 'All' && !in_array($categoryFilter, $serviceCategories, true))
    || ($speciesFilter !== 'All' && !in_array($speciesFilter, $speciesValues, true))
    || ($availabilityFilter !== 'All' && !in_array($availabilityFilter, $availabilityValues, true))
) {
    if ($isFragment) {
        $sendJson(['error' => 'Choose valid service filters.'], 400);
    }
    http_response_code(400);
    exit('Choose valid service filters.');
}

$services = [];
$summary = null;
$catalogLoadError = false;
try {
    $summaryStatement = $conn->prepare(
        "SELECT COUNT(*) AS total_services,
                COUNT(CASE WHEN status = 'Available' THEN 1 END) AS active_services,
                COUNT(CASE WHEN status = 'Unavailable' THEN 1 END) AS inactive_services
         FROM services"
    );
    $summaryStatement->execute();
    $summary = $summaryStatement->get_result()->fetch_assoc();
    $summaryStatement->close();

    $sql =
        'SELECT service_id, species, pet_size, category, service_name,
                description, price, price_note, duration, status
         FROM services
         WHERE 1 = 1';
    $parameters = [];
    $parameterTypes = '';
    if ($searchTerm !== '') {
        $sql .= ' AND service_name LIKE ?';
        $parameters[] = '%' . $searchTerm . '%';
        $parameterTypes .= 's';
    }
    if ($categoryFilter !== 'All') {
        $sql .= ' AND category = ?';
        $parameters[] = $categoryFilter;
        $parameterTypes .= 's';
    }
    if ($speciesFilter !== 'All') {
        $sql .= ' AND species = ?';
        $parameters[] = $speciesFilter;
        $parameterTypes .= 's';
    }
    if ($availabilityFilter !== 'All') {
        $sql .= ' AND status = ?';
        $parameters[] = $availabilityFilter;
        $parameterTypes .= 's';
    }
    $sql .= ' ORDER BY category, service_name, species, pet_size, service_id';
    $statement = $conn->prepare($sql);
    if ($parameters) {
        $statement->bind_param($parameterTypes, ...$parameters);
    }
    $statement->execute();
    $services = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
} catch (mysqli_sql_exception $exception) {
    error_log('Pawsome admin services catalog query failed: ' . $exception->getMessage());
    $catalogLoadError = true;
}

if ($isFragment) {
    $sendJson([
        'services' => $services,
        'summary' => $summary,
        'error' => $catalogLoadError,
    ], $catalogLoadError ? 500 : 200);
}

$groupedServices = [];
foreach ($services as $service) {
    $groupedServices[(string) $service['category']][] = $service;
}
$csrfToken = auth_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Manage Pawsome grooming services and prices.">
    <meta name="theme-color" content="#fbf8ff">
    <title>Services &amp; Prices | Pawsome Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800;900&family=Nunito:wght@500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/shared/portal.css">
    <link rel="stylesheet" href="../assets/css/admin/dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin/services.css">
</head>
<body class="customer-app admin-app admin-services-page">
    <a class="skip-link" href="#admin-main">Skip to content</a>
    <div class="customer-layout admin-layout">
        <?php require __DIR__ . '/../includes/admin_sidebar.php'; ?>
        <div class="customer-main-column">
            <header class="customer-topbar admin-topbar">
                <div>
                    <span class="customer-topbar-label">Pawsome administration</span>
                    <p class="customer-topbar-greeting">Hello, <?= auth_escape($adminFirstName) ?></p>
                </div>
                <form method="post" action="../customer/logout.php">
                    <input type="hidden" name="csrf_token" value="<?= auth_escape($csrfToken) ?>">
                    <button class="button button-primary button-small admin-logout" type="submit">Log out</button>
                </form>
            </header>

            <main class="customer-main admin-main admin-services-main" id="admin-main">
                <section class="customer-page-heading admin-page-heading">
                    <div>
                        <span class="eyebrow">Admin area</span>
                        <h1>Services <span>&amp; Prices</span></h1>
                        <p>Maintain the service catalog used by customer booking and price lists.</p>
                    </div>
                    <button class="button button-primary admin-service-add" id="add-service-button" type="button"<?= $categoryLoadError || !$serviceCategories ? ' disabled' : '' ?>>+ Add Service</button>
                </section>

                <?php if ($categoryLoadError): ?>
                    <p class="admin-alert" role="alert">We couldn't load service categories. Please try again later.</p>
                <?php endif; ?>
                <section class="admin-overview-grid admin-service-summary-grid" aria-label="Service summary">
                    <?php
                    $summaryCards = [
                        ['Total Services', 'total_services', 'All catalog records'],
                        ['Active Services', 'active_services', 'Available in customer price lists'],
                        ['Inactive Services', 'inactive_services', 'Unavailable for selection'],
                    ];
                    ?>
                    <?php foreach ($summaryCards as [$label, $key, $description]): ?>
                        <article class="admin-overview-card">
                            <span class="admin-overview-label"><?= auth_escape($label) ?></span>
                            <strong class="admin-overview-value">
                                <span data-summary="<?= auth_escape($key) ?>">
                                    <?= $catalogLoadError || $summary === null ? '—' : number_format((int) $summary[$key]) ?>
                                </span>
                            </strong>
                            <span class="admin-overview-description"><?= auth_escape($description) ?></span>
                        </article>
                    <?php endforeach; ?>
                </section>

                <section class="admin-panel admin-services-panel" aria-labelledby="service-catalog-title">
                    <div class="admin-panel-heading admin-services-panel-heading">
                        <div>
                            <span class="eyebrow">Current catalog</span>
                            <h2 id="service-catalog-title">Service Catalog</h2>
                        </div>
                        <form class="admin-service-filter-form" id="service-filter-form" method="get" action="services.php" role="search">
                            <div class="admin-service-filter-field admin-service-search-field">
                                <label for="service-search">Search</label>
                                <input id="service-search" name="search" type="search" maxlength="100" value="<?= auth_escape($searchTerm) ?>" placeholder="Search service name">
                            </div>
                            <div class="admin-service-filter-field">
                                <label for="service-category-filter">Category</label>
                                <select id="service-category-filter" name="category">
                                    <option value="All">All Categories</option>
                                    <?php foreach ($serviceCategories as $category): ?>
                                        <option value="<?= auth_escape((string) $category) ?>"<?= $categoryFilter === $category ? ' selected' : '' ?>><?= auth_escape((string) $category) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="admin-service-filter-field">
                                <label for="service-species-filter">Species</label>
                                <select id="service-species-filter" name="species">
                                    <option value="All">All Species</option>
                                    <?php foreach ($speciesValues as $species): ?>
                                        <option value="<?= auth_escape($species) ?>"<?= $speciesFilter === $species ? ' selected' : '' ?>><?= auth_escape($species === 'Other' ? 'Other Small Animal' : $species) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="admin-service-filter-field">
                                <label for="service-availability-filter">Availability</label>
                                <select id="service-availability-filter" name="availability">
                                    <option value="All">All Availability</option>
                                    <option value="Available"<?= $availabilityFilter === 'Available' ? ' selected' : '' ?>>Active</option>
                                    <option value="Unavailable"<?= $availabilityFilter === 'Unavailable' ? ' selected' : '' ?>>Inactive</option>
                                </select>
                            </div>
                        </form>
                    </div>
                    <p class="admin-service-feedback" id="service-feedback" role="alert" hidden></p>
                    <div id="service-results">
                        <?php if ($catalogLoadError): ?>
                            <p class="admin-panel-message" role="alert">We couldn't load the service catalog right now. Please try again later.</p>
                        <?php elseif (!$groupedServices): ?>
                            <p class="admin-panel-message">No services match the selected filters.</p>
                        <?php else: ?>
                            <?php foreach ($groupedServices as $category => $categoryServices): ?>
                                <section class="admin-service-category-group" aria-label="<?= auth_escape($category) ?>">
                                    <div class="admin-service-category-heading">
                                        <h3><?= auth_escape($category) ?></h3>
                                        <span><?= count($categoryServices) ?> services</span>
                                    </div>
                                    <div class="admin-table-scroll">
                                        <table class="admin-table admin-services-table">
                                            <thead>
                                                <tr>
                                                    <th scope="col">Service Name</th>
                                                    <th scope="col">Category</th>
                                                    <th scope="col">Species</th>
                                                    <th scope="col">Price</th>
                                                    <th scope="col">Duration</th>
                                                    <th scope="col">Availability</th>
                                                    <th scope="col">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($categoryServices as $service): ?>
                                                    <?php
                                                    $serviceData = [
                                                        'service_id' => (int) $service['service_id'],
                                                        'service_name' => (string) $service['service_name'],
                                                        'description' => (string) ($service['description'] ?? ''),
                                                        'category' => (string) $service['category'],
                                                        'species' => (string) $service['species'],
                                                        'pet_size' => (string) $service['pet_size'],
                                                        'price' => $service['price'] === null ? '' : (string) $service['price'],
                                                        'price_note' => (string) ($service['price_note'] ?? ''),
                                                        'duration' => (int) $service['duration'],
                                                    ];
                                                    ?>
                                                    <tr>
                                                        <td>
                                                            <strong><?= auth_escape((string) $service['service_name']) ?></strong>
                                                            <span class="admin-table-muted"><?= auth_escape((string) $service['pet_size']) ?> size</span>
                                                        </td>
                                                        <td><?= auth_escape((string) $service['category']) ?></td>
                                                        <td><?= auth_escape($service['species'] === 'Other' ? 'Other Small Animal' : (string) $service['species']) ?></td>
                                                        <td>
                                                            <?php if ($service['price'] === null): ?>
                                                                Not provided
                                                            <?php else: ?>
                                                                ₱<?= auth_escape(number_format((float) $service['price'], 2)) ?>
                                                                <?php if (!empty($service['price_note'])): ?>
                                                                    <span class="admin-table-muted"><?= auth_escape((string) $service['price_note']) ?></span>
                                                                <?php endif; ?>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><?= (int) $service['duration'] ?> min</td>
                                                        <td><span class="admin-service-status <?= $service['status'] === 'Available' ? 'is-active' : 'is-inactive' ?>"><?= $service['status'] === 'Available' ? 'Active' : 'Inactive' ?></span></td>
                                                        <td class="admin-service-actions">
                                                            <button
                                                                class="admin-service-edit"
                                                                type="button"
                                                                data-service="<?= auth_escape((string) json_encode($serviceData, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG)) ?>"
                                                            >Edit</button>
                                                            <button
                                                                class="admin-service-toggle <?= $service['status'] === 'Available' ? 'is-deactivate' : 'is-activate' ?>"
                                                                type="button"
                                                                data-service-id="<?= (int) $service['service_id'] ?>"
                                                                data-next-status="<?= $service['status'] === 'Available' ? 'Unavailable' : 'Available' ?>"
                                                            ><?= $service['status'] === 'Available' ? 'Deactivate' : 'Activate' ?></button>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </section>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <p class="admin-service-booking-note">
                        Availability controls customer selection. The existing booking flow continues to enforce its current supported service categories.
                    </p>
                </section>
            </main>
        </div>
    </div>

    <dialog class="admin-service-dialog" id="service-dialog" aria-labelledby="service-dialog-title">
        <form class="admin-service-dialog-form" id="service-form" method="post" action="services.php">
            <header class="admin-service-dialog-header">
                <div>
                    <span class="eyebrow">Service catalog</span>
                    <h2 id="service-dialog-title">Add Service</h2>
                </div>
                <button class="admin-service-dialog-close" id="close-service-dialog" type="button" aria-label="Close service form">&times;</button>
            </header>
            <p class="admin-service-form-error" id="service-form-error" role="alert" hidden></p>
            <input type="hidden" name="csrf_token" value="<?= auth_escape($csrfToken) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="service_id" id="service-id">
            <div class="admin-service-form-grid">
                <div class="admin-service-form-field">
                    <label for="service-name">Service name</label>
                    <input id="service-name" name="service_name" type="text" maxlength="100" required>
                </div>
                <div class="admin-service-form-field">
                    <label for="service-category">Category</label>
                    <select id="service-category" name="category" required>
                        <?php foreach ($serviceCategories as $category): ?>
                            <option value="<?= auth_escape((string) $category) ?>"><?= auth_escape((string) $category) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="admin-service-form-field">
                    <label for="service-species">Species</label>
                    <select id="service-species" name="species" required>
                        <?php foreach ($speciesValues as $species): ?>
                            <option value="<?= auth_escape($species) ?>"><?= auth_escape($species === 'Other' ? 'Other Small Animal' : $species) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="admin-service-form-field">
                    <label for="service-pet-size">Pet size</label>
                    <select id="service-pet-size" name="pet_size" required>
                        <?php foreach ($petSizeValues as $size): ?>
                            <option value="<?= auth_escape($size) ?>"><?= auth_escape($size === 'Any' ? 'Any size' : $size) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="admin-service-form-field">
                    <label for="service-price">Price</label>
                    <input id="service-price" name="price" type="number" min="0" max="99999999.99" step="0.01" placeholder="Leave blank if not set">
                </div>
                <div class="admin-service-form-field">
                    <label for="service-price-note">Price note</label>
                    <input id="service-price-note" name="price_note" type="text" maxlength="50" placeholder="e.g. Starting price">
                </div>
                <div class="admin-service-form-field">
                    <label for="service-duration">Duration (minutes)</label>
                    <input id="service-duration" name="duration" type="number" min="1" step="1" required>
                </div>
                <div class="admin-service-form-field admin-service-description-field">
                    <label for="service-description">Description</label>
                    <textarea id="service-description" name="description" rows="4" maxlength="10000"></textarea>
                </div>
            </div>
            <footer class="admin-service-dialog-actions">
                <button class="admin-service-cancel" id="cancel-service-dialog" type="button">Cancel</button>
                <button class="button button-primary" type="submit" id="save-service-button">Save Service</button>
            </footer>
        </form>
    </dialog>

    <script>
    (() => {
        const filterForm = document.getElementById('service-filter-form');
        const searchInput = document.getElementById('service-search');
        const categoryFilter = document.getElementById('service-category-filter');
        const speciesFilter = document.getElementById('service-species-filter');
        const availabilityFilter = document.getElementById('service-availability-filter');
        const results = document.getElementById('service-results');
        const feedback = document.getElementById('service-feedback');
        const dialog = document.getElementById('service-dialog');
        const serviceForm = document.getElementById('service-form');
        const formError = document.getElementById('service-form-error');
        const saveButton = document.getElementById('save-service-button');
        const csrfToken = <?= json_encode($csrfToken) ?>;
        let controller = null;
        let searchTimer = null;

        const escapeHtml = (value) => {
            const node = document.createElement('span');
            node.textContent = value == null ? '' : String(value);
            return node.innerHTML;
        };

        const formatPrice = (service) => {
            if (service.price === null || service.price === '') return 'Not provided';
            const amount = `₱${Number(service.price).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
            return `${amount}${service.price_note ? `<span class="admin-table-muted">${escapeHtml(service.price_note)}</span>` : ''}`;
        };

        const renderServices = (services) => {
            if (!services.length) {
                results.innerHTML = '<p class="admin-panel-message">No services match the selected filters.</p>';
                return;
            }
            const groups = new Map();
            services.forEach((service) => {
                if (!groups.has(service.category)) groups.set(service.category, []);
                groups.get(service.category).push(service);
            });

            results.innerHTML = Array.from(groups, ([category, categoryServices]) => `
                <section class="admin-service-category-group" aria-label="${escapeHtml(category)}">
                    <div class="admin-service-category-heading">
                        <h3>${escapeHtml(category)}</h3>
                        <span>${categoryServices.length} services</span>
                    </div>
                    <div class="admin-table-scroll">
                        <table class="admin-table admin-services-table">
                            <thead><tr>
                                <th scope="col">Service Name</th><th scope="col">Category</th>
                                <th scope="col">Species</th><th scope="col">Price</th>
                                <th scope="col">Duration</th><th scope="col">Availability</th><th scope="col">Actions</th>
                            </tr></thead>
                            <tbody>${categoryServices.map((service) => {
                                const active = service.status === 'Available';
                                const item = JSON.stringify({
                                    service_id: Number(service.service_id),
                                    service_name: service.service_name,
                                    description: service.description || '',
                                    category: service.category,
                                    species: service.species,
                                    pet_size: service.pet_size,
                                    price: service.price == null ? '' : service.price,
                                    price_note: service.price_note || '',
                                    duration: Number(service.duration)
                                });
                                return `<tr>
                                    <td><strong>${escapeHtml(service.service_name)}</strong><span class="admin-table-muted">${escapeHtml(service.pet_size)} size</span></td>
                                    <td>${escapeHtml(service.category)}</td>
                                    <td>${escapeHtml(service.species === 'Other' ? 'Other Small Animal' : service.species)}</td>
                                    <td>${formatPrice(service)}</td>
                                    <td>${Number(service.duration)} min</td>
                                    <td><span class="admin-service-status ${active ? 'is-active' : 'is-inactive'}">${active ? 'Active' : 'Inactive'}</span></td>
                                    <td class="admin-service-actions">
                                        <button class="admin-service-edit" type="button" data-service="${escapeHtml(item)}">Edit</button>
                                        <button class="admin-service-toggle ${active ? 'is-deactivate' : 'is-activate'}" type="button" data-service-id="${Number(service.service_id)}" data-next-status="${active ? 'Unavailable' : 'Available'}">${active ? 'Deactivate' : 'Activate'}</button>
                                    </td>
                                </tr>`;
                            }).join('')}</tbody>
                        </table>
                    </div>
                </section>`).join('');
        };

        const refreshCatalog = async () => {
            if (controller) controller.abort();
            controller = new AbortController();
            const savedScroll = window.scrollY;
            feedback.hidden = true;
            const url = new URL('services.php', window.location.href);
            url.searchParams.set('fragment', 'services');
            if (searchInput.value.trim()) url.searchParams.set('search', searchInput.value.trim());
            if (categoryFilter.value !== 'All') url.searchParams.set('category', categoryFilter.value);
            if (speciesFilter.value !== 'All') url.searchParams.set('species', speciesFilter.value);
            if (availabilityFilter.value !== 'All') url.searchParams.set('availability', availabilityFilter.value);

            try {
                const response = await fetch(url.toString(), { headers: { Accept: 'application/json' }, signal: controller.signal });
                const payload = await response.json();
                if (!response.ok || payload.error) throw new Error(payload.error || 'Catalog request failed.');
                renderServices(payload.services || []);
                ['total_services', 'active_services', 'inactive_services'].forEach((key) => {
                    const target = document.querySelector(`[data-summary="${key}"]`);
                    if (target) target.textContent = Number(payload.summary[key]).toLocaleString();
                });
                const pageUrl = new URL(window.location.href);
                ['search', 'category', 'species', 'availability', 'fragment'].forEach((key) => pageUrl.searchParams.delete(key));
                if (searchInput.value.trim()) pageUrl.searchParams.set('search', searchInput.value.trim());
                if (categoryFilter.value !== 'All') pageUrl.searchParams.set('category', categoryFilter.value);
                if (speciesFilter.value !== 'All') pageUrl.searchParams.set('species', speciesFilter.value);
                if (availabilityFilter.value !== 'All') pageUrl.searchParams.set('availability', availabilityFilter.value);
                window.history.replaceState({}, '', pageUrl);
            } catch (error) {
                if (error.name !== 'AbortError') {
                    feedback.textContent = 'We could not load the service catalog. Please try again.';
                    feedback.hidden = false;
                }
            } finally {
                window.requestAnimationFrame(() => window.scrollTo({ top: savedScroll, behavior: 'auto' }));
            }
        };

        const openServiceDialog = (service = null) => {
            serviceForm.reset();
            formError.hidden = true;
            const categorySelect = document.getElementById('service-category');
            const firstCategory = categorySelect.options.length ? categorySelect.options[0].value : '';
            document.getElementById('service-id').value = service ? service.service_id : '';
            document.getElementById('service-name').value = service ? service.service_name : '';
            document.getElementById('service-description').value = service ? service.description : '';
            categorySelect.value = service
                ? service.category
                : (categoryFilter.value === 'All' ? firstCategory : categoryFilter.value);
            document.getElementById('service-species').value = service ? service.species : 'Dog';
            document.getElementById('service-pet-size').value = service ? service.pet_size : 'Any';
            document.getElementById('service-price').value = service ? service.price : '';
            document.getElementById('service-price-note').value = service ? service.price_note : '';
            document.getElementById('service-duration').value = service ? service.duration : '';
            document.getElementById('service-dialog-title').textContent = service ? 'Edit Service' : 'Add Service';
            saveButton.textContent = service ? 'Save Changes' : 'Save Service';
            dialog.showModal();
        };

        document.getElementById('add-service-button').addEventListener('click', () => openServiceDialog());
        document.getElementById('close-service-dialog').addEventListener('click', () => dialog.close());
        document.getElementById('cancel-service-dialog').addEventListener('click', () => dialog.close());
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) dialog.close();
        });

        results.addEventListener('click', async (event) => {
            const editButton = event.target.closest('.admin-service-edit');
            if (editButton) {
                try {
                    openServiceDialog(JSON.parse(editButton.dataset.service));
                } catch (error) {
                    feedback.textContent = 'We could not open this service for editing. Refresh the page and try again.';
                    feedback.hidden = false;
                }
                return;
            }

            const toggleButton = event.target.closest('.admin-service-toggle');
            if (!toggleButton) return;
            const nextStatus = toggleButton.dataset.nextStatus;
            if (!window.confirm(`${nextStatus === 'Available' ? 'Activate' : 'Deactivate'} this service?`)) return;
            toggleButton.disabled = true;
            try {
                const body = new URLSearchParams({
                    csrf_token: csrfToken,
                    action: 'toggle',
                    service_id: toggleButton.dataset.serviceId,
                    status: nextStatus
                });
                const response = await fetch('services.php', {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' },
                    body
                });
                const payload = await response.json();
                if (!response.ok || payload.error) throw new Error(payload.error || 'Availability update failed.');
                await refreshCatalog();
            } catch (error) {
                feedback.textContent = error.message || 'We could not update service availability. Please try again.';
                feedback.hidden = false;
                toggleButton.disabled = false;
            }
        });

        serviceForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            formError.hidden = true;
            saveButton.disabled = true;
            try {
                const response = await fetch('services.php', {
                    method: 'POST',
                    headers: { Accept: 'application/json' },
                    body: new FormData(serviceForm)
                });
                const payload = await response.json();
                if (!response.ok || payload.error) throw new Error(payload.error || 'Could not save this service.');
                dialog.close();
                await refreshCatalog();
            } catch (error) {
                formError.textContent = error.message || 'We could not save this service. Please try again.';
                formError.hidden = false;
            } finally {
                saveButton.disabled = false;
            }
        });

        searchInput.addEventListener('input', () => {
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(refreshCatalog, 180);
        });
        [categoryFilter, speciesFilter, availabilityFilter].forEach((filter) => filter.addEventListener('change', refreshCatalog));
        filterForm.addEventListener('submit', (event) => event.preventDefault());
    })();
    </script>
</body>
</html>
