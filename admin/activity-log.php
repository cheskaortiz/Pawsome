<?php
require_once __DIR__ . '/../includes/auth.php';
auth_require_admin();
require_once __DIR__ . '/../config/database.php';

$adminFirstName = (string) ($_SESSION['first_name'] ?? 'admin');
$activeAdminPage = 'activity-log';
$searchTerm = $_GET['search'] ?? '';
$roleFilter = $_GET['role'] ?? 'All';
$activityTypeFilter = $_GET['activity_type'] ?? 'All';
$fromDate = $_GET['from_date'] ?? '';
$toDate = $_GET['to_date'] ?? '';
$sortOrder = $_GET['sort'] ?? 'newest';
$requestedPage = $_GET['page'] ?? '1';
$manilaTimezone = new DateTimeZone('Asia/Manila');
$validDate = static function (string $date) use ($manilaTimezone): bool {
    if ($date === '') {
        return true;
    }

    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $manilaTimezone);
    $errors = DateTimeImmutable::getLastErrors();
    return $parsed !== false
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        && $parsed->format('Y-m-d') === $date;
};

if (
    !is_string($searchTerm)
    || auth_string_length(trim($searchTerm)) > 100
    || !is_string($roleFilter)
    || !in_array($roleFilter, ['All', 'admin', 'customer'], true)
    || !is_string($activityTypeFilter)
    || auth_string_length($activityTypeFilter) > 64
    || !is_string($fromDate)
    || !is_string($toDate)
    || !$validDate($fromDate)
    || !$validDate($toDate)
    || ($fromDate !== '' && $toDate !== '' && $fromDate > $toDate)
    || !is_string($sortOrder)
    || !in_array($sortOrder, ['newest', 'oldest'], true)
    || !is_string($requestedPage)
    || filter_var($requestedPage, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
) {
    http_response_code(400);
    exit('Choose valid activity log filters and try again.');
}

$searchTerm = trim($searchTerm);
$activityTypes = [];
$summary = null;
$activities = [];
$resultCount = 0;
$totalPages = 1;
$currentPage = (int) $requestedPage;
$dataLoadError = false;

$whereParts = [];
$filterParameters = [];
$filterTypes = '';
if ($searchTerm !== '') {
    $whereParts[] = '(user_name LIKE ? OR action_type LIKE ? OR description LIKE ?)';
    $searchPattern = '%' . $searchTerm . '%';
    array_push($filterParameters, $searchPattern, $searchPattern, $searchPattern);
    $filterTypes .= 'sss';
}
if ($roleFilter !== 'All') {
    $whereParts[] = 'role = ?';
    $filterParameters[] = $roleFilter;
    $filterTypes .= 's';
}
if ($activityTypeFilter !== 'All') {
    $whereParts[] = 'action_type = ?';
    $filterParameters[] = $activityTypeFilter;
    $filterTypes .= 's';
}
if ($fromDate !== '') {
    $whereParts[] = 'created_at >= ?';
    $filterParameters[] = $fromDate . ' 00:00:00';
    $filterTypes .= 's';
}
if ($toDate !== '') {
    $toDateExclusive = (new DateTimeImmutable($toDate, $manilaTimezone))->modify('+1 day')->format('Y-m-d');
    $whereParts[] = 'created_at < ?';
    $filterParameters[] = $toDateExclusive . ' 00:00:00';
    $filterTypes .= 's';
}
$whereSql = $whereParts ? ' WHERE ' . implode(' AND ', $whereParts) : '';
$queryParameters = [];
foreach ([
    'search' => $searchTerm,
    'role' => $roleFilter,
    'activity_type' => $activityTypeFilter,
    'from_date' => $fromDate,
    'to_date' => $toDate,
    'sort' => $sortOrder,
] as $key => $value) {
    if ($value !== '' && $value !== 'All' && !($key === 'sort' && $value === 'newest')) {
        $queryParameters[$key] = $value;
    }
}

try {
    $summaryStatement = $conn->prepare(
        'SELECT COUNT(*) AS total_activities,
                COALESCE(SUM(DATE(created_at) = CURDATE()), 0) AS today_activities,
                COALESCE(SUM(role = \'admin\'), 0) AS admin_activities,
                COALESCE(SUM(role = \'customer\'), 0) AS customer_activities
         FROM activity_logs'
    );
    $summaryStatement->execute();
    $summary = $summaryStatement->get_result()->fetch_assoc();
    $summaryStatement->close();

    $typeStatement = $conn->prepare(
        'SELECT DISTINCT action_type
         FROM activity_logs
         ORDER BY action_type ASC'
    );
    $typeStatement->execute();
    $activityTypes = array_column($typeStatement->get_result()->fetch_all(MYSQLI_ASSOC), 'action_type');
    $typeStatement->close();
    if ($activityTypeFilter !== 'All' && !in_array($activityTypeFilter, $activityTypes, true)) {
        http_response_code(400);
        exit('Choose a valid activity type.');
    }

    $countStatement = $conn->prepare('SELECT COUNT(*) AS matching_activities FROM activity_logs' . $whereSql);
    if ($filterParameters) {
        $countStatement->bind_param($filterTypes, ...$filterParameters);
    }
    $countStatement->execute();
    $resultCount = (int) $countStatement->get_result()->fetch_assoc()['matching_activities'];
    $countStatement->close();

    $pageSize = 50;
    $totalPages = max(1, (int) ceil($resultCount / $pageSize));
    $currentPage = min($currentPage, $totalPages);
    $offset = ($currentPage - 1) * $pageSize;
    $sortDirection = $sortOrder === 'oldest' ? 'ASC' : 'DESC';
    $activityStatement = $conn->prepare(
        'SELECT activity_id, user_name, role, action_type, description, details_json, created_at
         FROM activity_logs'
        . $whereSql
        . ' ORDER BY created_at ' . $sortDirection . ', activity_id ' . $sortDirection
        . ' LIMIT ? OFFSET ?'
    );
    $activityParameters = $filterParameters;
    $activityParameters[] = $pageSize;
    $activityParameters[] = $offset;
    $activityTypesForQuery = $filterTypes . 'ii';
    $activityStatement->bind_param($activityTypesForQuery, ...$activityParameters);
    $activityStatement->execute();
    $activities = $activityStatement->get_result()->fetch_all(MYSQLI_ASSOC);
    $activityStatement->close();
} catch (mysqli_sql_exception $exception) {
    error_log('Pawsome admin activity log query failed: ' . $exception->getMessage());
    $dataLoadError = true;
}

$formatActivityType = static function (string $activityType): string {
    return ucwords(str_replace(['.', '_', '-'], ' ', $activityType));
};
$makePageUrl = static function (int $page) use ($queryParameters): string {
    $parameters = $queryParameters;
    if ($page > 1) {
        $parameters['page'] = $page;
    }
    return 'activity-log.php' . ($parameters ? '?' . http_build_query($parameters) : '');
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Review recorded Pawsome customer and administrator activity.">
    <meta name="theme-color" content="#fbf8ff">
    <title>Activity Log | Pawsome Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800;900&family=Nunito:wght@500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/shared/portal.css">
    <link rel="stylesheet" href="../assets/css/admin/dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin/activity-log.css">
</head>
<body class="customer-app admin-app admin-activity-log-page">
    <a class="skip-link" href="#admin-main">Skip to content</a>
    <div class="customer-layout admin-layout">
        <?php require __DIR__ . '/../includes/admin_sidebar.php'; ?>
        <div class="customer-main-column">
            <header class="customer-topbar admin-topbar">
                <div>
                    <span class="customer-topbar-label">Pawsome administration</span>
                    <p class="customer-topbar-greeting">Hello, <?= auth_escape($adminFirstName) ?></p>
                </div>
            </header>

            <main class="customer-main admin-main admin-activity-log-main" id="admin-main">
                <section class="customer-page-heading admin-page-heading">
                    <div>
                        <span class="eyebrow">Accountability</span>
                        <h1>Activity <span>Log</span></h1>
                        <p>Review successful actions recorded across customer and admin workflows.</p>
                    </div>
                </section>

                <section class="admin-overview-grid admin-activity-summary-grid" aria-label="Activity summary">
                    <?php
                    $summaryCards = [
                        ['Total Activities', 'total_activities', 'All recorded events'],
                        ['Today’s Activities', 'today_activities', 'Recorded today'],
                        ['Admin Activities', 'admin_activities', 'Actions by admins'],
                        ['Customer Activities', 'customer_activities', 'Actions by customers'],
                    ];
                    ?>
                    <?php foreach ($summaryCards as [$label, $key, $description]): ?>
                        <article class="admin-overview-card">
                            <span class="admin-overview-label"><?= auth_escape($label) ?></span>
                            <strong class="admin-overview-value">
                                <?= $dataLoadError || $summary === null ? '—' : number_format((int) $summary[$key]) ?>
                            </strong>
                            <span class="admin-overview-description"><?= auth_escape($description) ?></span>
                        </article>
                    <?php endforeach; ?>
                </section>

                <?php if ($dataLoadError): ?>
                    <p class="admin-alert" role="alert">We couldn't load activity logs right now. Please try again later.</p>
                <?php endif; ?>

                <section class="admin-panel admin-activity-panel" aria-labelledby="activity-list-title">
                    <div class="admin-panel-heading admin-activity-panel-heading">
                        <div>
                            <span class="eyebrow">Recorded events</span>
                            <h2 id="activity-list-title">Activity history</h2>
                            <?php if (!$dataLoadError): ?>
                                <p><?= number_format($resultCount) ?> matching <?= $resultCount === 1 ? 'activity' : 'activities' ?></p>
                            <?php endif; ?>
                        </div>
                        <form class="admin-activity-filter-form" id="activity-filter-form" method="get" action="activity-log.php" role="search">
                            <label class="admin-activity-search-field" for="activity-search">
                                <span>Search</span>
                                <input id="activity-search" name="search" type="search" maxlength="100" value="<?= auth_escape($searchTerm) ?>" placeholder="User or activity">
                            </label>
                            <label for="activity-role-filter">
                                <span>Role</span>
                                <select id="activity-role-filter" name="role">
                                    <option value="All">All roles</option>
                                    <option value="admin"<?= $roleFilter === 'admin' ? ' selected' : '' ?>>Admin</option>
                                    <option value="customer"<?= $roleFilter === 'customer' ? ' selected' : '' ?>>Customer</option>
                                </select>
                            </label>
                            <label for="activity-type-filter">
                                <span>Activity type</span>
                                <select id="activity-type-filter" name="activity_type">
                                    <option value="All">All activities</option>
                                    <?php foreach ($activityTypes as $activityType): ?>
                                        <option value="<?= auth_escape($activityType) ?>"<?= $activityTypeFilter === $activityType ? ' selected' : '' ?>>
                                            <?= auth_escape($formatActivityType($activityType)) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label for="activity-from-date">
                                <span>From</span>
                                <input id="activity-from-date" name="from_date" type="date" value="<?= auth_escape($fromDate) ?>">
                            </label>
                            <label for="activity-to-date">
                                <span>To</span>
                                <input id="activity-to-date" name="to_date" type="date" value="<?= auth_escape($toDate) ?>">
                            </label>
                            <label for="activity-sort">
                                <span>Sort</span>
                                <select id="activity-sort" name="sort">
                                    <option value="newest"<?= $sortOrder === 'newest' ? ' selected' : '' ?>>Newest first</option>
                                    <option value="oldest"<?= $sortOrder === 'oldest' ? ' selected' : '' ?>>Oldest first</option>
                                </select>
                            </label>
                        </form>
                    </div>

                    <?php if (!$dataLoadError && !$activities): ?>
                        <p class="admin-panel-message">No activities match the selected filters.</p>
                    <?php elseif (!$dataLoadError): ?>
                        <div class="admin-activity-table-wrap">
                            <table class="admin-activity-table">
                                <thead>
                                    <tr>
                                        <th scope="col">User Name</th>
                                        <th scope="col">Role</th>
                                        <th scope="col">Activity</th>
                                        <th scope="col">Description</th>
                                        <th scope="col">Date &amp; Time</th>
                                        <th scope="col">Details</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($activities as $activity): ?>
                                        <?php
                                        $details = [];
                                        if ($activity['details_json'] !== null) {
                                            $decodedDetails = json_decode((string) $activity['details_json'], true);
                                            if (is_array($decodedDetails) && json_last_error() === JSON_ERROR_NONE) {
                                                $details = $decodedDetails;
                                            } else {
                                                error_log('Pawsome activity log details could not be decoded for activity #' . $activity['activity_id'] . '.');
                                            }
                                        }
                                        $activityDateTime = new DateTimeImmutable((string) $activity['created_at'], $manilaTimezone);
                                        $detailsJson = json_encode(
                                            $details,
                                            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP
                                        );
                                        if ($detailsJson === false) {
                                            error_log('Pawsome activity log details could not be encoded for activity #' . $activity['activity_id'] . '.');
                                            $detailsJson = '{}';
                                        }
                                        ?>
                                        <tr>
                                            <td data-label="User Name"><?= auth_escape($activity['user_name']) ?></td>
                                            <td data-label="Role"><span class="admin-activity-role is-<?= auth_escape($activity['role']) ?>"><?= auth_escape(ucfirst($activity['role'])) ?></span></td>
                                            <td data-label="Activity"><?= auth_escape($formatActivityType($activity['action_type'])) ?></td>
                                            <td class="admin-activity-description" data-label="Description"><?= auth_escape($activity['description']) ?></td>
                                            <td data-label="Date &amp; Time"><time datetime="<?= auth_escape($activityDateTime->format(DATE_ATOM)) ?>"><?= auth_escape($activityDateTime->format('M j, Y · g:i A')) ?></time></td>
                                            <td class="admin-activity-actions">
                                                <button
                                                    class="admin-activity-details-button"
                                                    type="button"
                                                    data-activity-details
                                                    data-user="<?= auth_escape($activity['user_name']) ?>"
                                                    data-role="<?= auth_escape(ucfirst($activity['role'])) ?>"
                                                    data-type="<?= auth_escape($formatActivityType($activity['action_type'])) ?>"
                                                    data-description="<?= auth_escape($activity['description']) ?>"
                                                    data-created="<?= auth_escape($activityDateTime->format('M j, Y · g:i A')) ?>"
                                                    data-details="<?= auth_escape($detailsJson) ?>"
                                                >View Details</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if ($totalPages > 1): ?>
                            <nav class="admin-activity-pagination" aria-label="Activity log pages">
                                <span>Page <?= number_format($currentPage) ?> of <?= number_format($totalPages) ?></span>
                                <div>
                                    <?php if ($currentPage > 1): ?>
                                        <a href="<?= auth_escape($makePageUrl($currentPage - 1)) ?>" rel="prev">Previous</a>
                                    <?php endif; ?>
                                    <?php if ($currentPage < $totalPages): ?>
                                        <a href="<?= auth_escape($makePageUrl($currentPage + 1)) ?>" rel="next">Next</a>
                                    <?php endif; ?>
                                </div>
                            </nav>
                        <?php endif; ?>
                    <?php endif; ?>
                </section>
            </main>
        </div>
    </div>

    <dialog class="admin-activity-dialog" id="activity-details-dialog" aria-labelledby="activity-dialog-title">
        <div class="admin-activity-dialog-content">
            <header>
                <div>
                    <span class="eyebrow">Recorded event</span>
                    <h2 id="activity-dialog-title">Activity Details</h2>
                </div>
                <button class="admin-activity-dialog-close" type="button" aria-label="Close activity details" data-close-activity-dialog>&times;</button>
            </header>
            <dl class="admin-activity-dialog-meta">
                <div><dt>User</dt><dd id="activity-dialog-user"></dd></div>
                <div><dt>Role</dt><dd id="activity-dialog-role"></dd></div>
                <div><dt>Activity</dt><dd id="activity-dialog-type"></dd></div>
                <div><dt>Date &amp; Time</dt><dd id="activity-dialog-created"></dd></div>
            </dl>
            <section>
                <h3>Description</h3>
                <p id="activity-dialog-description"></p>
            </section>
            <section>
                <h3>Additional details</h3>
                <dl class="admin-activity-details-list" id="activity-dialog-details"></dl>
            </section>
        </div>
    </dialog>
    <script>
        const activityFilterForm = document.getElementById('activity-filter-form');
        const activitySearch = document.getElementById('activity-search');
        let activitySearchTimer;

        activityFilterForm.querySelectorAll('select, input[type="date"]').forEach((filter) => {
            filter.addEventListener('change', () => activityFilterForm.requestSubmit());
        });
        activitySearch.addEventListener('input', () => {
            window.clearTimeout(activitySearchTimer);
            activitySearchTimer = window.setTimeout(() => activityFilterForm.requestSubmit(), 300);
        });

        const activityDialog = document.getElementById('activity-details-dialog');
        const activityDetailsList = document.getElementById('activity-dialog-details');
        document.querySelectorAll('[data-activity-details]').forEach((button) => {
            button.addEventListener('click', () => {
                document.getElementById('activity-dialog-user').textContent = button.dataset.user;
                document.getElementById('activity-dialog-role').textContent = button.dataset.role;
                document.getElementById('activity-dialog-type').textContent = button.dataset.type;
                document.getElementById('activity-dialog-created').textContent = button.dataset.created;
                document.getElementById('activity-dialog-description').textContent = button.dataset.description;
                activityDetailsList.replaceChildren();

                const details = JSON.parse(button.dataset.details);
                const entries = Object.entries(details);
                if (entries.length === 0) {
                    const empty = document.createElement('dd');
                    empty.textContent = 'No additional details.';
                    activityDetailsList.append(empty);
                } else {
                    entries.forEach(([key, value]) => {
                        const term = document.createElement('dt');
                        const description = document.createElement('dd');
                        term.textContent = key.replace(/_/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
                        description.textContent = Array.isArray(value)
                            ? value.join(', ')
                            : (value === null ? '—' : String(value));
                        activityDetailsList.append(term, description);
                    });
                }
                activityDialog.showModal();
            });
        });
        document.querySelectorAll('[data-close-activity-dialog]').forEach((button) => {
            button.addEventListener('click', () => activityDialog.close());
        });
        activityDialog.addEventListener('click', (event) => {
            if (event.target === activityDialog) {
                activityDialog.close();
            }
        });
    </script>
</body>
</html>
