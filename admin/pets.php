<?php
require_once __DIR__ . '/../includes/auth.php';
auth_require_admin();
require_once __DIR__ . '/../config/database.php';

$adminFirstName = (string) ($_SESSION['first_name'] ?? 'admin');
$activeAdminPage = 'pets';
$searchTerm = $_GET['search'] ?? '';
$speciesFilter = $_GET['species'] ?? 'All';
$breedFilter = $_GET['breed'] ?? 'All';
$vaccinationFilter = $_GET['vaccination'] ?? 'All';
$isFragment = ($_GET['fragment'] ?? null) === 'pets';

if (
    !is_string($searchTerm)
    || auth_string_length(trim($searchTerm)) > 100
    || !is_string($speciesFilter)
    || !is_string($breedFilter)
    || !is_string($vaccinationFilter)
) {
    http_response_code(400);
    echo $isFragment
        ? json_encode(['error' => 'Enter valid pet filters.'])
        : 'Enter valid pet filters.';
    exit;
}
$searchTerm = trim($searchTerm);
$summary = null;
$summaryLoadError = false;
$breedOptions = [];
$pets = [];
$loadError = false;

if (!$isFragment) {
    try {
        $statement = $conn->prepare(
            "SELECT COUNT(*) AS total_pets,
                    COUNT(CASE WHEN LOWER(species) = 'dog' THEN 1 END) AS total_dogs,
                    COUNT(CASE WHEN LOWER(species) = 'cat' THEN 1 END) AS total_cats
             FROM pets"
        );
        $statement->execute();
        $summary = $statement->get_result()->fetch_assoc();
        $statement->close();

        $statement = $conn->prepare(
            'SELECT DISTINCT breed
             FROM pets
             WHERE breed IS NOT NULL AND TRIM(breed) <> \'\'
             ORDER BY breed ASC'
        );
        $statement->execute();
        $breedOptions = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
        $statement->close();
    } catch (mysqli_sql_exception $exception) {
        error_log('Pawsome admin pet summary query failed: ' . $exception->getMessage());
        $summaryLoadError = true;
    }
}

$speciesOptions = [];
try {
    $statement = $conn->prepare(
        'SELECT DISTINCT species FROM pets WHERE species IS NOT NULL AND TRIM(species) <> \'\' ORDER BY species ASC'
    );
    $statement->execute();
    $speciesOptions = array_column($statement->get_result()->fetch_all(MYSQLI_ASSOC), 'species');
    $statement->close();

    if (!$isFragment) {
        $statement = $conn->prepare(
            'SELECT DISTINCT breed FROM pets
             WHERE breed IS NOT NULL AND TRIM(breed) <> \'\'
             ORDER BY breed ASC'
        );
        $statement->execute();
        $breedOptions = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
        $statement->close();
    }
} catch (mysqli_sql_exception $exception) {
    error_log('Pawsome admin pet filter query failed: ' . $exception->getMessage());
}

try {
    $sql =
        'SELECT p.pet_id, p.pet_name, p.species, p.breed, p.breed_size,
                p.photo_path, p.vaccination_proof_path,
                u.user_id, u.first_name, u.last_name
         FROM pets AS p
         INNER JOIN users AS u ON u.user_id = p.user_id
         WHERE u.role = \'customer\'';
    $conditions = [];
    $parameters = [];
    $parameterTypes = '';

    if ($searchTerm !== '') {
        $conditions[] = "(p.pet_name LIKE ?
                           OR p.breed LIKE ?
                           OR CONCAT_WS(' ', u.first_name, u.last_name) LIKE ?)";
        $pattern = '%' . $searchTerm . '%';
        array_push($parameters, $pattern, $pattern, $pattern);
        $parameterTypes .= 'sss';
    }
    if ($speciesFilter !== '' && $speciesFilter !== 'All') {
        $conditions[] = 'p.species = ?';
        $parameters[] = $speciesFilter;
        $parameterTypes .= 's';
    }
    if ($breedFilter !== '' && $breedFilter !== 'All') {
        $conditions[] = 'p.breed = ?';
        $parameters[] = $breedFilter;
        $parameterTypes .= 's';
    }
    if ($vaccinationFilter === 'Vaccinated') {
        $conditions[] = 'p.vaccination_proof_path IS NOT NULL AND TRIM(p.vaccination_proof_path) <> \'\'';
    } elseif ($vaccinationFilter === 'Not Vaccinated') {
        $conditions[] = '(p.vaccination_proof_path IS NULL OR TRIM(p.vaccination_proof_path) = \'\')';
    } elseif ($vaccinationFilter !== '' && $vaccinationFilter !== 'All') {
        $vaccinationFilter = 'All';
    }

    if ($conditions) {
        $sql .= ' AND ' . implode(' AND ', $conditions);
    }
    $sql .= ' ORDER BY p.created_at DESC, p.pet_id DESC';

    $statement = $conn->prepare($sql);
    if ($parameters) {
        $statement->bind_param($parameterTypes, ...$parameters);
    }
    $statement->execute();
    $pets = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
} catch (mysqli_sql_exception $exception) {
    error_log('Pawsome admin pets query failed: ' . $exception->getMessage());
    $loadError = true;
}

if ($isFragment) {
    header('Content-Type: application/json');
    echo json_encode(['pets' => $pets, 'error' => $loadError], JSON_UNESCAPED_UNICODE);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Manage Pawsome pet profiles.">
    <meta name="theme-color" content="#fbf8ff">
    <title>Pets | Pawsome Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800;900&family=Nunito:wght@500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/shared/portal.css">
    <link rel="stylesheet" href="../assets/css/admin/dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin/pets.css">
</head>
<body class="customer-app admin-app admin-pets-page">
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
                    <input type="hidden" name="csrf_token" value="<?= auth_escape(auth_csrf_token()) ?>">
                    <button class="button button-primary button-small admin-logout" type="submit">Log out</button>
                </form>
            </header>

            <main class="customer-main admin-main admin-pets-main" id="admin-main">
                <section class="customer-page-heading admin-page-heading">
                    <div>
                        <span class="eyebrow">Admin area</span>
                        <h1>Registered <span>Pets</span></h1>
                        <p>Search pet profiles and review owner and vaccination information.</p>
                    </div>
                </section>

                <section class="admin-overview-grid admin-pet-summary-grid" aria-label="Pet summary">
                    <?php
                    $summaryCards = [
                        ['Total Pets', 'total_pets', 'Registered pet profiles'],
                        ['Dogs', 'total_dogs', 'Dogs in the system'],
                        ['Cats', 'total_cats', 'Cats in the system'],
                    ];
                    ?>
                    <?php foreach ($summaryCards as [$label, $key, $description]): ?>
                        <article class="admin-overview-card">
                            <span class="admin-overview-label"><?= auth_escape($label) ?></span>
                            <strong class="admin-overview-value">
                                <?= $summaryLoadError || $summary === null ? '—' : number_format((int) $summary[$key]) ?>
                            </strong>
                            <span class="admin-overview-description"><?= auth_escape($description) ?></span>
                        </article>
                    <?php endforeach; ?>
                </section>

                <section class="admin-panel admin-pets-panel" aria-labelledby="pets-list-title">
                    <div class="admin-panel-heading admin-pets-panel-heading">
                        <div>
                            <span class="eyebrow">Pet directory</span>
                            <h2 id="pets-list-title">Pets</h2>
                        </div>
                        <form class="admin-pet-filter-form" id="pet-filter-form" role="search" method="get" action="pets.php">
                            <div class="admin-pet-filter-field admin-pet-search-field">
                                <label for="pet-search">Search</label>
                                <input type="search" id="pet-search" name="search" value="<?= auth_escape($searchTerm) ?>" maxlength="100" placeholder="Pet name, breed, or owner">
                            </div>
                            <div class="admin-pet-filter-field">
                                <label for="pet-species">Species</label>
                                <select id="pet-species" name="species">
                                    <option value="All">All Species</option>
                                    <?php foreach ($speciesOptions as $species): ?>
                                        <option value="<?= auth_escape((string) $species) ?>"<?= $speciesFilter === $species ? ' selected' : '' ?>><?= auth_escape((string) $species) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="admin-pet-filter-field">
                                <label for="pet-breed">Breed</label>
                                <select id="pet-breed" name="breed">
                                    <option value="All">All Breeds</option>
                                    <?php foreach ($breedOptions as $breedRow): ?>
                                        <?php $breed = (string) $breedRow['breed']; ?>
                                        <option value="<?= auth_escape($breed) ?>"<?= $breedFilter === $breed ? ' selected' : '' ?>><?= auth_escape($breed) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="admin-pet-filter-field">
                                <label for="pet-vaccination">Vaccination</label>
                                <select id="pet-vaccination" name="vaccination">
                                    <?php foreach (['All', 'Vaccinated', 'Not Vaccinated'] as $option): ?>
                                        <option value="<?= auth_escape($option) ?>"<?= $vaccinationFilter === $option ? ' selected' : '' ?>><?= auth_escape($option === 'All' ? 'All Statuses' : $option) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </form>
                    </div>
                    <p class="admin-pet-feedback" id="pet-filter-feedback" role="alert" hidden></p>
                    <div class="admin-table-scroll" id="pet-results">
                        <?php if ($loadError): ?>
                            <p class="admin-panel-message" role="alert">We couldn't load pets right now. Please try again later.</p>
                        <?php elseif (!$pets): ?>
                            <p class="admin-panel-message">No pets match the selected filters.</p>
                        <?php else: ?>
                            <table class="admin-table admin-pets-table">
                                <thead>
                                    <tr>
                                        <th scope="col">Pet Photo</th>
                                        <th scope="col">Pet Name</th>
                                        <th scope="col">Species</th>
                                        <th scope="col">Breed</th>
                                        <th scope="col">Owner</th>
                                        <th scope="col">Breed Size</th>
                                        <th scope="col">Vaccination Status</th>
                                        <th scope="col">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pets as $pet): ?>
                                        <?php $hasProof = trim((string) ($pet['vaccination_proof_path'] ?? '')) !== ''; ?>
                                        <tr>
                                            <td>
                                                <?php if (!empty($pet['photo_path'])): ?>
                                                    <img class="admin-pet-table-photo" src="../customer/pet-media.php?pet_id=<?= (int) $pet['pet_id'] ?>&amp;type=photo" alt="<?= auth_escape((string) $pet['pet_name']) ?>">
                                                <?php else: ?>
                                                    <span class="admin-pet-table-placeholder" aria-label="No photo">🐾</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><strong><?= auth_escape((string) $pet['pet_name']) ?></strong></td>
                                            <td><?= auth_escape((string) $pet['species']) ?></td>
                                            <td><?= auth_escape((string) ($pet['breed'] ?: 'Not provided')) ?></td>
                                            <td><?= auth_escape(trim((string) $pet['first_name'] . ' ' . (string) $pet['last_name'])) ?></td>
                                            <td><?= auth_escape((string) ($pet['breed_size'] ?: 'Not provided')) ?></td>
                                            <td><span class="admin-vaccination-status <?= $hasProof ? 'is-vaccinated' : 'is-not-vaccinated' ?>"><?= $hasProof ? 'Vaccinated' : 'Not provided' ?></span></td>
                                            <td><a class="admin-pet-details-button" href="pet-details.php?id=<?= (int) $pet['pet_id'] ?>">View Details</a></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </section>
            </main>
        </div>
    </div>
    <script>
    (() => {
        const form = document.getElementById('pet-filter-form');
        const search = document.getElementById('pet-search');
        const species = document.getElementById('pet-species');
        const breed = document.getElementById('pet-breed');
        const vaccination = document.getElementById('pet-vaccination');
        const results = document.getElementById('pet-results');
        const feedback = document.getElementById('pet-filter-feedback');
        let controller = null;
        let timer = null;

        const escapeHtml = (value) => {
            const node = document.createElement('span');
            node.textContent = value == null ? '' : String(value);
            return node.innerHTML;
        };

        const render = (pets) => {
            if (!pets.length) {
                results.innerHTML = '<p class="admin-panel-message">No pets match the selected filters.</p>';
                return;
            }
            const rows = pets.map((pet) => {
                const owner = [pet.first_name, pet.last_name].filter(Boolean).join(' ').trim();
                const vaccinated = String(pet.vaccination_proof_path || '').trim() !== '';
                const photo = pet.photo_path
                    ? `<img class="admin-pet-table-photo" src="../customer/pet-media.php?pet_id=${Number(pet.pet_id)}&amp;type=photo" alt="${escapeHtml(pet.pet_name)}">`
                    : '<span class="admin-pet-table-placeholder" aria-label="No photo">🐾</span>';
                return `<tr>
                    <td>${photo}</td>
                    <td><strong>${escapeHtml(pet.pet_name)}</strong></td>
                    <td>${escapeHtml(pet.species)}</td>
                    <td>${escapeHtml(pet.breed || 'Not provided')}</td>
                    <td>${escapeHtml(owner)}</td>
                    <td>${escapeHtml(pet.breed_size || 'Not provided')}</td>
                    <td><span class="admin-vaccination-status ${vaccinated ? 'is-vaccinated' : 'is-not-vaccinated'}">${vaccinated ? 'Vaccinated' : 'Not provided'}</span></td>
                    <td><a class="admin-pet-details-button" href="pet-details.php?id=${Number(pet.pet_id)}">View Details</a></td>
                </tr>`;
            }).join('');
            results.innerHTML = `<table class="admin-table admin-pets-table">
                <thead><tr><th scope="col">Pet Photo</th><th scope="col">Pet Name</th><th scope="col">Species</th><th scope="col">Breed</th><th scope="col">Owner</th><th scope="col">Breed Size</th><th scope="col">Vaccination Status</th><th scope="col">Actions</th></tr></thead>
                <tbody>${rows}</tbody>
            </table>`;
        };

        const update = async () => {
            if (controller) controller.abort();
            controller = new AbortController();
            const savedScroll = window.scrollY;
            feedback.hidden = true;
            try {
                const url = new URL('pets.php', window.location.href);
                url.searchParams.set('fragment', 'pets');
                if (search.value.trim()) url.searchParams.set('search', search.value.trim());
                if (species.value !== 'All') url.searchParams.set('species', species.value);
                if (breed.value !== 'All') url.searchParams.set('breed', breed.value);
                if (vaccination.value !== 'All') url.searchParams.set('vaccination', vaccination.value);
                const response = await fetch(url.toString(), { headers: { Accept: 'application/json' }, signal: controller.signal });
                if (!response.ok) throw new Error('Request failed');
                const payload = await response.json();
                if (payload.error) throw new Error('Load failed');
                render(payload.pets || []);

                const pageUrl = new URL(window.location.href);
                ['search', 'species', 'breed', 'vaccination', 'fragment'].forEach((key) => pageUrl.searchParams.delete(key));
                if (search.value.trim()) pageUrl.searchParams.set('search', search.value.trim());
                if (species.value !== 'All') pageUrl.searchParams.set('species', species.value);
                if (breed.value !== 'All') pageUrl.searchParams.set('breed', breed.value);
                if (vaccination.value !== 'All') pageUrl.searchParams.set('vaccination', vaccination.value);
                window.history.replaceState({}, '', pageUrl);
            } catch (error) {
                if (error.name !== 'AbortError') {
                    feedback.textContent = 'We could not complete that search. Please try again.';
                    feedback.hidden = false;
                }
            } finally {
                window.requestAnimationFrame(() => window.scrollTo({ top: savedScroll, behavior: 'auto' }));
            }
        };

        search.addEventListener('input', () => {
            clearTimeout(timer);
            timer = setTimeout(update, 180);
        });
        [species, breed, vaccination].forEach((control) => control.addEventListener('change', update));
        form.addEventListener('submit', (event) => event.preventDefault());
    })();
    </script>
</body>
</html>
