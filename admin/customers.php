<?php
require_once __DIR__ . '/../includes/auth.php';
auth_require_admin();
require_once __DIR__ . '/../config/database.php';

$adminFirstName = (string) ($_SESSION['first_name'] ?? 'admin');
$activeAdminPage = 'customers';
$isFragment = ($_GET['fragment'] ?? null) === 'customers';
$searchTerm = $_GET['search'] ?? '';
$registeredFrom = $_GET['registered_from'] ?? '';
$registeredTo = $_GET['registered_to'] ?? '';
$petFilter = $_GET['pets'] ?? 'all';
$sortOrder = $_GET['sort'] ?? 'newest';

if (
    !is_string($searchTerm)
    || auth_string_length(trim($searchTerm)) > 100
    || !is_string($registeredFrom)
    || !is_string($registeredTo)
    || !is_string($petFilter)
    || !is_string($sortOrder)
) {
    if ($isFragment) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Enter valid customer filters.']);
        exit;
    }
    http_response_code(400);
    exit('Enter valid customer filters.');
}
$searchTerm = trim($searchTerm);
$registeredFrom = trim($registeredFrom);
$registeredTo = trim($registeredTo);
$validDate = static function (string $date): bool {
    if ($date === '') {
        return true;
    }
    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    $dateErrors = DateTimeImmutable::getLastErrors();
    return $parsedDate !== false
        && ($dateErrors === false || ($dateErrors['warning_count'] === 0 && $dateErrors['error_count'] === 0))
        && $parsedDate->format('Y-m-d') === $date;
};
if (
    !$validDate($registeredFrom)
    || !$validDate($registeredTo)
    || ($registeredFrom !== '' && $registeredTo !== '' && $registeredFrom > $registeredTo)
    || !in_array($petFilter, ['all', 'none', 'one_two', 'three_plus'], true)
    || !in_array($sortOrder, ['newest', 'oldest'], true)
) {
    if ($isFragment) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Enter valid customer filters.']);
        exit;
    }
    http_response_code(400);
    exit('Enter valid customer filters.');
}

$summary = null;
$summaryLoadError = false;
$customers = [];
$loadError = false;

if (!$isFragment) {
    try {
        $statement = $conn->prepare(
            "SELECT
                (SELECT COUNT(*) FROM users WHERE role = 'customer') AS total_customers,
                (SELECT COUNT(*) FROM users
                 WHERE role = 'customer'
                   AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                   AND created_at < DATE_ADD(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 1 MONTH)) AS new_customers,
                (SELECT COUNT(*) FROM pets AS p
                 INNER JOIN users AS u ON u.user_id = p.user_id AND u.role = 'customer') AS total_customer_pets,
                (SELECT COUNT(*) FROM appointments AS a
                 INNER JOIN users AS u ON u.user_id = a.user_id AND u.role = 'customer') AS total_appointments"
        );
        $statement->execute();
        $summary = $statement->get_result()->fetch_assoc();
        $statement->close();
    } catch (mysqli_sql_exception $exception) {
        error_log('Pawsome admin customer summary query failed: ' . $exception->getMessage());
        $summaryLoadError = true;
    }
}

try {
    $sql =
        'SELECT u.user_id, u.first_name, u.last_name, u.email, u.phone, u.created_at,
                COALESCE(pet_counts.pet_count, 0) AS pet_count,
                COALESCE(appointment_counts.appointment_count, 0) AS appointment_count
         FROM users AS u
         LEFT JOIN (
             SELECT user_id, COUNT(*) AS pet_count
             FROM pets
             GROUP BY user_id
         ) AS pet_counts ON pet_counts.user_id = u.user_id
         LEFT JOIN (
             SELECT user_id, COUNT(*) AS appointment_count
             FROM appointments
             GROUP BY user_id
         ) AS appointment_counts ON appointment_counts.user_id = u.user_id
         WHERE u.role = \'customer\'';
    $parameters = [];
    $parameterTypes = '';

    if ($searchTerm !== '') {
        $sql .= " AND (CONCAT_WS(' ', u.first_name, u.last_name) LIKE ?
                       OR u.email LIKE ?
                       OR u.phone LIKE ?)";
        $pattern = '%' . $searchTerm . '%';
        $parameters = [$pattern, $pattern, $pattern];
        $parameterTypes = 'sss';
    }
    if ($registeredFrom !== '') {
        $sql .= ' AND u.created_at >= ?';
        $parameters[] = $registeredFrom . ' 00:00:00';
        $parameterTypes .= 's';
    }
    if ($registeredTo !== '') {
        $sql .= ' AND u.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
        $parameters[] = $registeredTo;
        $parameterTypes .= 's';
    }
    if ($petFilter === 'none') {
        $sql .= ' AND COALESCE(pet_counts.pet_count, 0) = 0';
    } elseif ($petFilter === 'one_two') {
        $sql .= ' AND COALESCE(pet_counts.pet_count, 0) BETWEEN 1 AND 2';
    } elseif ($petFilter === 'three_plus') {
        $sql .= ' AND COALESCE(pet_counts.pet_count, 0) >= 3';
    }
    $sql .= $sortOrder === 'oldest'
        ? ' ORDER BY u.created_at ASC, u.user_id ASC'
        : ' ORDER BY u.created_at DESC, u.user_id DESC';

    $statement = $conn->prepare($sql);
    if ($parameters) {
        $statement->bind_param($parameterTypes, ...$parameters);
    }
    $statement->execute();
    $customers = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
} catch (mysqli_sql_exception $exception) {
    error_log('Pawsome admin customers query failed: ' . $exception->getMessage());
    $loadError = true;
}

if ($isFragment) {
    header('Content-Type: application/json');
    echo json_encode([
        'customers' => $customers,
        'error' => $loadError,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Manage Pawsome customer accounts.">
    <meta name="theme-color" content="#fbf8ff">
    <title>Customers | Pawsome Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800;900&family=Nunito:wght@500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/shared/portal.css">
    <link rel="stylesheet" href="../assets/css/admin/dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin/customers.css">
</head>
<body class="customer-app admin-app admin-customers-page">
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

            <main class="customer-main admin-main admin-customers-main" id="admin-main">
                <section class="customer-page-heading admin-page-heading">
                    <div>
                        <span class="eyebrow">Admin area</span>
                        <h1>Customer <span>Accounts</span></h1>
                        <p>Find customers and review their profiles, pets, and appointment history.</p>
                    </div>
                </section>

                <section class="admin-overview-grid admin-customer-summary-grid" aria-label="Customer summary">
                    <?php
                    $summaryCards = [
                        ['Total Customers', 'total_customers', 'Registered customer accounts'],
                        ['New Customers', 'new_customers', 'Registered this month'],
                        ['Total Customer Pets', 'total_customer_pets', 'Pets registered by customers'],
                        ['Total Appointments', 'total_appointments', 'Appointments by customers'],
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
                <?php if ($summaryLoadError): ?>
                    <p class="admin-alert" role="alert">We couldn't load the customer summary. Please try again later.</p>
                <?php endif; ?>

                <section class="admin-panel admin-customers-panel" aria-labelledby="customers-list-title">
                    <div class="admin-panel-heading admin-customers-panel-heading">
                        <div>
                            <span class="eyebrow">Customer directory</span>
                            <h2 id="customers-list-title">Customers</h2>
                        </div>
                        <form class="admin-customer-filters" id="customer-search-form" role="search" method="get" action="customers.php">
                            <label class="admin-customer-search" for="customer-search">
                                Search customers
                                <input
                                    type="search"
                                    id="customer-search"
                                    name="search"
                                    value="<?= auth_escape($searchTerm) ?>"
                                    maxlength="100"
                                    autocomplete="off"
                                    placeholder="Name, email, or phone"
                                >
                            </label>
                            <label for="customer-registered-from">
                                Joined from
                                <input type="date" id="customer-registered-from" name="registered_from" value="<?= auth_escape($registeredFrom) ?>">
                            </label>
                            <label for="customer-registered-to">
                                Joined to
                                <input type="date" id="customer-registered-to" name="registered_to" value="<?= auth_escape($registeredTo) ?>">
                            </label>
                            <label for="customer-pet-filter">
                                Registered pets
                                <select id="customer-pet-filter" name="pets">
                                    <option value="all" <?= $petFilter === 'all' ? 'selected' : '' ?>>Any number</option>
                                    <option value="none" <?= $petFilter === 'none' ? 'selected' : '' ?>>No pets</option>
                                    <option value="one_two" <?= $petFilter === 'one_two' ? 'selected' : '' ?>>1–2 pets</option>
                                    <option value="three_plus" <?= $petFilter === 'three_plus' ? 'selected' : '' ?>>3+ pets</option>
                                </select>
                            </label>
                            <label for="customer-sort">
                                Sort by
                                <select id="customer-sort" name="sort">
                                    <option value="newest" <?= $sortOrder === 'newest' ? 'selected' : '' ?>>Newest joined</option>
                                    <option value="oldest" <?= $sortOrder === 'oldest' ? 'selected' : '' ?>>Oldest joined</option>
                                </select>
                            </label>
                        </form>
                    </div>
                    <p class="admin-customer-feedback" id="customer-search-feedback" role="alert" hidden></p>
                    <div class="admin-table-scroll" id="customer-results">
                        <?php if ($loadError): ?>
                            <p class="admin-panel-message" role="alert">We couldn't load customers right now. Please try again later.</p>
                        <?php elseif (!$customers): ?>
                            <p class="admin-panel-message">No customers match your search.</p>
                        <?php else: ?>
                            <table class="admin-table admin-customers-table">
                                <thead>
                                    <tr>
                                        <th scope="col">Reference</th>
                                        <th scope="col">Customer</th>
                                        <th scope="col">Contact</th>
                                        <th scope="col">Registered Pets</th>
                                        <th scope="col">Appointments</th>
                                        <th scope="col">Date Joined</th>
                                        <th scope="col">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($customers as $customer): ?>
                                        <?php
                                        $customerName = trim((string) $customer['first_name'] . ' ' . (string) $customer['last_name']);
                                        $nameParts = preg_split('/\s+/', $customerName, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                                        $initials = '';
                                        foreach (array_slice($nameParts, 0, 2) as $namePart) {
                                            $initials .= function_exists('mb_substr')
                                                ? mb_substr($namePart, 0, 1, 'UTF-8')
                                                : substr($namePart, 0, 1);
                                        }
                                        ?>
                                        <tr>
                                            <td class="admin-reference">#<?= (int) $customer['user_id'] ?></td>
                                            <td>
                                                <div class="admin-customer-identity">
                                                    <span class="admin-customer-avatar" aria-hidden="true"><?= auth_escape($initials ?: '?') ?></span>
                                                    <span><strong><?= auth_escape($customerName) ?></strong></span>
                                                </div>
                                            </td>
                                            <td class="admin-customer-contact">
                                                <a href="mailto:<?= auth_escape((string) $customer['email']) ?>"><?= auth_escape((string) $customer['email']) ?></a>
                                                <span><?= auth_escape(trim((string) ($customer['phone'] ?? '')) ?: 'Not provided') ?></span>
                                            </td>
                                            <td><?= number_format((int) $customer['pet_count']) ?></td>
                                            <td><?= number_format((int) $customer['appointment_count']) ?></td>
                                            <td><?= auth_escape(date('M j, Y', strtotime((string) $customer['created_at']))) ?></td>
                                            <td>
                                                <a class="admin-customer-details-button" href="customer-details.php?id=<?= (int) $customer['user_id'] ?>">
                                                    View Details
                                                </a>
                                            </td>
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
        const form = document.getElementById('customer-search-form');
        const input = document.getElementById('customer-search');
        const results = document.getElementById('customer-results');
        const feedback = document.getElementById('customer-search-feedback');
        let requestController = null;
        let debounceTimer = null;

        const escapeHtml = (value) => {
            const node = document.createElement('span');
            node.textContent = value == null ? '' : String(value);
            return node.innerHTML;
        };

        const formatDate = (value) => {
            if (!value) return '';
            const date = new Date(String(value).replace(' ', 'T'));
            return Number.isNaN(date.getTime())
                ? String(value)
                : date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        };

        const renderCustomers = (customers) => {
            if (!customers.length) {
                results.innerHTML = '<p class="admin-panel-message">No customers match your search.</p>';
                return;
            }

            const rows = customers.map((customer) => {
                const name = [customer.first_name, customer.last_name].filter(Boolean).join(' ').trim();
                const phone = String(customer.phone || '').trim() || 'Not provided';
                const initials = name.split(/\s+/).filter(Boolean).slice(0, 2)
                    .map((part) => Array.from(part)[0] || '').join('') || '?';
                return `<tr>
                    <td class="admin-reference">#${Number(customer.user_id)}</td>
                    <td><div class="admin-customer-identity">
                        <span class="admin-customer-avatar" aria-hidden="true">${escapeHtml(initials)}</span>
                        <span><strong>${escapeHtml(name)}</strong></span>
                    </div></td>
                    <td class="admin-customer-contact">
                        <a href="mailto:${escapeHtml(customer.email)}">${escapeHtml(customer.email)}</a>
                        <span>${escapeHtml(phone)}</span>
                    </td>
                    <td>${Number(customer.pet_count)}</td>
                    <td>${Number(customer.appointment_count)}</td>
                    <td>${escapeHtml(formatDate(customer.created_at))}</td>
                    <td><a class="admin-customer-details-button" href="customer-details.php?id=${Number(customer.user_id)}">View Details</a></td>
                </tr>`;
            }).join('');

            results.innerHTML = `<table class="admin-table admin-customers-table">
                <thead><tr>
                    <th scope="col">Reference</th>
                    <th scope="col">Customer</th>
                    <th scope="col">Contact</th>
                    <th scope="col">Registered Pets</th>
                    <th scope="col">Appointments</th>
                    <th scope="col">Date Joined</th>
                    <th scope="col">Actions</th>
                </tr></thead>
                <tbody>${rows}</tbody>
            </table>`;
        };

        const search = async () => {
            if (requestController) requestController.abort();
            requestController = new AbortController();
            const savedScroll = window.scrollY;
            feedback.hidden = true;

            try {
                const url = new URL('customers.php', window.location.href);
                url.searchParams.set('fragment', 'customers');
                for (const [key, value] of new FormData(form).entries()) {
                    if (String(value).trim()) url.searchParams.set(key, String(value).trim());
                    else url.searchParams.delete(key);
                }
                const response = await fetch(url.toString(), {
                    headers: { 'Accept': 'application/json' },
                    signal: requestController.signal
                });
                if (!response.ok) throw new Error('Search request failed.');
                const payload = await response.json();
                if (payload.error) throw new Error('We could not load customers right now.');
                renderCustomers(payload.customers || []);
                const pageUrl = new URL(window.location.href);
                for (const [key, value] of new FormData(form).entries()) {
                    if (String(value).trim()) pageUrl.searchParams.set(key, String(value).trim());
                    else pageUrl.searchParams.delete(key);
                }
                pageUrl.searchParams.delete('fragment');
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

        input.addEventListener('input', () => {
            window.clearTimeout(debounceTimer);
            debounceTimer = window.setTimeout(search, 180);
        });
        form.querySelectorAll('input:not([type="search"]), select').forEach((control) => {
            control.addEventListener('change', () => {
                window.clearTimeout(debounceTimer);
                search();
            });
        });
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            window.clearTimeout(debounceTimer);
            search();
        });
    })();
    </script>
</body>
</html>
