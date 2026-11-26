<?php
require_once __DIR__ . '/../includes/auth.php';
auth_require_admin();
require_once __DIR__ . '/../config/database.php';

$adminFirstName = (string) ($_SESSION['first_name'] ?? 'admin');
$activeAdminPage = 'customers';
$searchTerm = $_GET['search'] ?? '';
$isFragment = ($_GET['fragment'] ?? null) === 'customers';

if (!is_string($searchTerm) || auth_string_length(trim($searchTerm)) > 100) {
    if ($isFragment) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Enter a valid customer search.']);
        exit;
    }
    http_response_code(400);
    exit('Enter a valid customer search.');
}
$searchTerm = trim($searchTerm);

$summary = null;
$summaryLoadError = false;
$customers = [];
$loadError = false;

if (!$isFragment) {
    try {
        $statement = $conn->prepare(
            "SELECT COUNT(*) AS total_customers,
                    COUNT(CASE WHEN created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN 1 END) AS new_customers
             FROM users
             WHERE role = 'customer'"
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
        'SELECT user_id, first_name, last_name, email, phone, created_at
         FROM users
         WHERE role = \'customer\'';
    $parameters = [];
    $parameterTypes = '';

    if ($searchTerm !== '') {
        $sql .= " AND (CONCAT_WS(' ', first_name, last_name) LIKE ?
                       OR email LIKE ?
                       OR phone LIKE ?)";
        $pattern = '%' . $searchTerm . '%';
        $parameters = [$pattern, $pattern, $pattern];
        $parameterTypes = 'sss';
    }
    $sql .= ' ORDER BY created_at DESC, user_id DESC';

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
                        <form class="admin-customer-search" id="customer-search-form" role="search" method="get" action="customers.php">
                            <label for="customer-search">Search customers</label>
                            <input
                                type="search"
                                id="customer-search"
                                name="search"
                                value="<?= auth_escape($searchTerm) ?>"
                                maxlength="100"
                                autocomplete="off"
                                placeholder="Name, email, or phone"
                            >
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
                                        <th scope="col">Customer ID</th>
                                        <th scope="col">Customer Name</th>
                                        <th scope="col">Email</th>
                                        <th scope="col">Phone</th>
                                        <th scope="col">Date Registered</th>
                                        <th scope="col">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($customers as $customer): ?>
                                        <tr>
                                            <td class="admin-reference">#<?= (int) $customer['user_id'] ?></td>
                                            <td><strong><?= auth_escape(trim((string) $customer['first_name'] . ' ' . (string) $customer['last_name'])) ?></strong></td>
                                            <td><?= auth_escape((string) $customer['email']) ?></td>
                                            <td><?= auth_escape(trim((string) ($customer['phone'] ?? '')) ?: 'Not provided') ?></td>
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
                return `<tr>
                    <td class="admin-reference">#${Number(customer.user_id)}</td>
                    <td><strong>${escapeHtml(name)}</strong></td>
                    <td>${escapeHtml(customer.email)}</td>
                    <td>${escapeHtml(phone)}</td>
                    <td>${escapeHtml(formatDate(customer.created_at))}</td>
                    <td><a class="admin-customer-details-button" href="customer-details.php?id=${Number(customer.user_id)}">View Details</a></td>
                </tr>`;
            }).join('');

            results.innerHTML = `<table class="admin-table admin-customers-table">
                <thead><tr>
                    <th scope="col">Customer ID</th>
                    <th scope="col">Customer Name</th>
                    <th scope="col">Email</th>
                    <th scope="col">Phone</th>
                    <th scope="col">Date Registered</th>
                    <th scope="col">Actions</th>
                </tr></thead>
                <tbody>${rows}</tbody>
            </table>`;
        };

        const search = async () => {
            if (requestController) requestController.abort();
            requestController = new AbortController();
            const savedScroll = window.scrollY;
            const term = input.value.trim();
            feedback.hidden = true;

            try {
                const url = new URL('customers.php', window.location.href);
                url.searchParams.set('fragment', 'customers');
                if (term) url.searchParams.set('search', term);
                const response = await fetch(url.toString(), {
                    headers: { 'Accept': 'application/json' },
                    signal: requestController.signal
                });
                if (!response.ok) throw new Error('Search request failed.');
                const payload = await response.json();
                if (payload.error) throw new Error('We could not load customers right now.');
                renderCustomers(payload.customers || []);
                const pageUrl = new URL(window.location.href);
                if (term) pageUrl.searchParams.set('search', term);
                else pageUrl.searchParams.delete('search');
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
        form.addEventListener('submit', (event) => {
            event.preventDefault();
        });
    })();
    </script>
</body>
</html>
