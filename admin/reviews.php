<?php
require_once __DIR__ . '/../includes/auth.php';
auth_require_admin();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/activity_log.php';

$adminFirstName = (string) ($_SESSION['first_name'] ?? 'admin');
$activeAdminPage = 'reviews';
$csrfToken = auth_csrf_token();
$searchTerm = $_GET['search'] ?? '';
$ratingFilter = $_GET['rating'] ?? 'All';
$statusFilter = $_GET['status'] ?? 'All';
$fromDate = $_GET['from_date'] ?? '';
$toDate = $_GET['to_date'] ?? '';
$ratingFilters = ['All', '1', '2', '3', '4', '5'];
$statusFilters = ['All', 'Pending', 'Approved', 'Removed'];
$validDate = static function (string $date): bool {
    if ($date === '') {
        return true;
    }

    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    $errors = DateTimeImmutable::getLastErrors();
    return $parsed !== false
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        && $parsed->format('Y-m-d') === $date;
};

if (
    !is_string($searchTerm)
    || auth_string_length(trim($searchTerm)) > 100
    || !is_string($ratingFilter)
    || !in_array($ratingFilter, $ratingFilters, true)
    || !is_string($statusFilter)
    || !in_array($statusFilter, $statusFilters, true)
    || !is_string($fromDate)
    || !is_string($toDate)
    || !$validDate($fromDate)
    || !$validDate($toDate)
    || ($fromDate !== '' && $toDate !== '' && $fromDate > $toDate)
) {
    http_response_code(400);
    exit('Choose valid review filters and try again.');
}
$searchTerm = trim($searchTerm);

$queryParameters = [];
if ($searchTerm !== '') {
    $queryParameters['search'] = $searchTerm;
}
if ($ratingFilter !== 'All') {
    $queryParameters['rating'] = $ratingFilter;
}
if ($statusFilter !== 'All') {
    $queryParameters['status'] = $statusFilter;
}
if ($fromDate !== '') {
    $queryParameters['from_date'] = $fromDate;
}
if ($toDate !== '') {
    $queryParameters['to_date'] = $toDate;
}
$returnUrl = 'reviews.php';
if ($queryParameters) {
    $returnUrl .= '?' . http_build_query($queryParameters);
}
$isFragmentRequest = ($_GET['fragment'] ?? '') === '1';

$postString = static function (string $key): string {
    return isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : '';
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!auth_csrf_is_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        exit('Your session expired. Reload the page and try again.');
    }
    $action = $postString('action');
    if (!in_array($action, ['approve', 'remove', 'restore'], true)) {
        http_response_code(400);
        exit('Choose a valid review action.');
    }

    $reviewId = filter_var(
        $postString('review_id'),
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );
    if ($reviewId === false) {
        http_response_code(400);
        exit('Choose a valid review.');
    }

    $transactionStarted = false;
    try {
        $conn->begin_transaction();
        $transactionStarted = true;
        $statement = $conn->prepare(
            'SELECT status, status_before_removal
             FROM reviews
             WHERE review_id = ?
             FOR UPDATE'
        );
        $statement->bind_param('i', $reviewId);
        $statement->execute();
        $reviewState = $statement->get_result()->fetch_assoc();
        $statement->close();

        if (!$reviewState) {
            $conn->rollback();
            $transactionStarted = false;
            auth_set_flash('admin_reviews_error', 'That review could not be found.');
        } elseif ($action === 'approve' && $reviewState['status'] === 'Pending') {
            $statement = $conn->prepare(
                "UPDATE reviews
                 SET status = 'Approved', status_before_removal = NULL, is_hidden = 0
                 WHERE review_id = ?"
            );
            $statement->bind_param('i', $reviewId);
            $statement->execute();
            $statement->close();
            activity_log_current_user(
                $conn,
                'review.approved',
                'Approved review #' . $reviewId . '.',
                ['review_id' => (int) $reviewId, 'from_status' => 'Pending', 'to_status' => 'Approved']
            );
            $conn->commit();
            $transactionStarted = false;
            auth_set_flash('admin_reviews_success', 'Review approved.');
        } elseif ($action === 'remove' && in_array($reviewState['status'], ['Pending', 'Approved'], true)) {
            $statement = $conn->prepare(
                "UPDATE reviews
                 SET status = 'Removed', status_before_removal = ?, is_hidden = 1
                 WHERE review_id = ?"
            );
            $previousStatus = (string) $reviewState['status'];
            $statement->bind_param('si', $previousStatus, $reviewId);
            $statement->execute();
            $statement->close();
            activity_log_current_user(
                $conn,
                'review.removed',
                'Removed review #' . $reviewId . '.',
                ['review_id' => (int) $reviewId, 'from_status' => $previousStatus, 'to_status' => 'Removed']
            );
            $conn->commit();
            $transactionStarted = false;
            auth_set_flash('admin_reviews_success', 'Review removed.');
        } elseif ($action === 'restore' && $reviewState['status'] === 'Removed') {
            $restoredStatus = in_array($reviewState['status_before_removal'], ['Pending', 'Approved'], true)
                ? (string) $reviewState['status_before_removal']
                : 'Pending';
            $isHidden = $restoredStatus === 'Approved' ? 0 : 1;
            $statement = $conn->prepare(
                'UPDATE reviews
                 SET status = ?, status_before_removal = NULL, is_hidden = ?
                 WHERE review_id = ?'
            );
            $statement->bind_param('sii', $restoredStatus, $isHidden, $reviewId);
            $statement->execute();
            $statement->close();
            activity_log_current_user(
                $conn,
                'review.restored',
                'Restored review #' . $reviewId . '.',
                ['review_id' => (int) $reviewId, 'from_status' => 'Removed', 'to_status' => $restoredStatus]
            );
            $conn->commit();
            $transactionStarted = false;
            auth_set_flash('admin_reviews_success', 'Review restored.');
        } else {
            $conn->rollback();
            $transactionStarted = false;
            auth_set_flash('admin_reviews_error', 'That review cannot be changed to the selected status.');
        }
    } catch (mysqli_sql_exception $exception) {
        if ($transactionStarted) {
            $conn->rollback();
        }
        error_log('Pawsome admin review status update failed: ' . $exception->getMessage());
        auth_set_flash('admin_reviews_error', 'We could not update this review right now. Please try again.');
    }

    header('Location: ' . $returnUrl, true, 303);
    exit;
}

$successMessage = auth_take_flash('admin_reviews_success');
$errorMessage = auth_take_flash('admin_reviews_error');
$summary = null;
$summaryLoadError = false;
try {
    $statement = $conn->prepare(
        'SELECT COUNT(*) AS total_reviews,
                COALESCE(AVG(CASE WHEN status = \'Approved\' THEN rating END), 0) AS average_rating,
                COALESCE(SUM(status = \'Pending\'), 0) AS pending_reviews,
                COALESCE(SUM(status = \'Approved\'), 0) AS approved_reviews,
                COALESCE(SUM(status = \'Removed\'), 0) AS removed_reviews
         FROM reviews'
    );
    $statement->execute();
    $summary = $statement->get_result()->fetch_assoc();
    $statement->close();
} catch (mysqli_sql_exception $exception) {
    error_log('Pawsome admin review summary query failed: ' . $exception->getMessage());
    $summaryLoadError = true;
}

$statusCounts = [
    'All' => (int) ($summary['total_reviews'] ?? 0),
    'Pending' => (int) ($summary['pending_reviews'] ?? 0),
    'Approved' => (int) ($summary['approved_reviews'] ?? 0),
    'Removed' => (int) ($summary['removed_reviews'] ?? 0),
];

$reviews = [];
$reviewLoadError = false;
try {
    $sql =
        'SELECT r.review_id, r.appointment_id, r.rating, r.feedback, r.status, r.created_at,
                u.first_name, u.last_name, p.pet_name,
                CASE
                    WHEN s.category IN (\'Basic Groom\', \'Premium Groom\')
                    THEN CONCAT_WS(\' - \', s.category, s.service_name)
                    ELSE s.service_name
                END AS service_display_name
         FROM reviews AS r
         INNER JOIN appointments AS a
            ON a.appointment_id = r.appointment_id AND a.user_id = r.user_id
         INNER JOIN users AS u ON u.user_id = r.user_id AND u.role = \'customer\'
         INNER JOIN pets AS p ON p.pet_id = a.pet_id AND p.user_id = a.user_id
         INNER JOIN services AS s ON s.service_id = a.service_id';
    $conditions = [];
    $parameters = [];
    $parameterTypes = '';

    if ($searchTerm !== '') {
        $conditions[] = "(CONCAT_WS(' ', u.first_name, u.last_name) LIKE ?
            OR p.pet_name LIKE ?
            OR s.service_name LIKE ?
            OR s.category LIKE ?
            OR r.feedback LIKE ?
            OR CAST(r.appointment_id AS CHAR) LIKE ?)";
        $pattern = '%' . $searchTerm . '%';
        array_push($parameters, $pattern, $pattern, $pattern, $pattern, $pattern, $pattern);
        $parameterTypes .= 'ssssss';
    }
    if ($ratingFilter !== 'All') {
        $conditions[] = 'r.rating = ?';
        $parameters[] = (int) $ratingFilter;
        $parameterTypes .= 'i';
    }
    if ($statusFilter !== 'All') {
        $conditions[] = 'r.status = ?';
        $parameters[] = $statusFilter;
        $parameterTypes .= 's';
    }
    if ($fromDate !== '') {
        $conditions[] = 'r.created_at >= ?';
        $parameters[] = $fromDate . ' 00:00:00';
        $parameterTypes .= 's';
    }
    if ($toDate !== '') {
        $conditions[] = 'r.created_at <= ?';
        $parameters[] = $toDate . ' 23:59:59';
        $parameterTypes .= 's';
    }
    if ($conditions) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }
    $sql .= ' ORDER BY r.created_at DESC, r.review_id DESC';

    $statement = $conn->prepare($sql);
    if ($parameters) {
        $bindArguments = [$parameterTypes];
        foreach (array_keys($parameters) as $parameterIndex) {
            $bindArguments[] = &$parameters[$parameterIndex];
        }
        call_user_func_array([$statement, 'bind_param'], $bindArguments);
    }
    $statement->execute();
    $reviews = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
} catch (mysqli_sql_exception $exception) {
    error_log('Pawsome admin reviews query failed: ' . $exception->getMessage());
    $reviewLoadError = true;
}

$feedbackPreview = static function (string $feedback): string {
    if (auth_string_length($feedback) <= 180) {
        return $feedback;
    }
    if (function_exists('mb_substr')) {
        return mb_substr($feedback, 0, 180, 'UTF-8') . '...';
    }
    return substr($feedback, 0, 180) . '...';
};

$renderReviewCards = static function (array $reviewRows) use ($csrfToken, $returnUrl, $feedbackPreview): string {
    ob_start();
    ?>
    <div class="admin-review-cards">
        <?php foreach ($reviewRows as $review): ?>
            <?php
            $customerName = trim((string) $review['first_name'] . ' ' . (string) $review['last_name']);
            $status = (string) $review['status'];
            $reviewDate = date('M j, Y', strtotime((string) $review['created_at']));
            $rating = (int) $review['rating'];
            $reviewData = [
                'customer' => $customerName,
                'pet' => (string) $review['pet_name'],
                'service' => (string) $review['service_display_name'],
                'appointment' => (int) $review['appointment_id'],
                'rating' => $rating,
                'feedback' => (string) $review['feedback'],
                'date' => $reviewDate,
                'status' => $status,
            ];
            $reviewJson = json_encode(
                $reviewData,
                JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG
            );
            ?>
            <article class="admin-review-card">
                <header class="admin-review-card-header">
                    <div class="admin-review-card-customer">
                        <h3><?= auth_escape($customerName) ?></h3>
                        <p><?= auth_escape((string) $review['pet_name']) ?> · <?= auth_escape((string) $review['service_display_name']) ?></p>
                    </div>
                    <span class="admin-review-status is-<?= strtolower(auth_escape($status)) ?>"><?= auth_escape($status) ?></span>
                </header>
                <div class="admin-review-card-rating">
                    <span class="admin-review-rating" aria-label="<?= $rating ?> out of 5 stars"><?= str_repeat('★', $rating) ?><span><?= str_repeat('☆', 5 - $rating) ?></span></span>
                    <span><?= $rating ?> / 5</span>
                </div>
                <p class="admin-review-card-feedback"><?= nl2br(auth_escape($feedbackPreview((string) $review['feedback']))) ?></p>
                <footer class="admin-review-card-footer">
                    <span class="admin-review-card-date"><?= auth_escape($reviewDate) ?> · Appointment #<?= (int) $review['appointment_id'] ?></span>
                    <div class="admin-review-card-actions">
                        <button class="admin-review-view-button" type="button" data-review="<?= auth_escape(is_string($reviewJson) ? $reviewJson : '{}') ?>">View Details</button>
                        <?php if ($status === 'Pending'): ?>
                            <?php foreach (['approve' => 'Approve', 'remove' => 'Remove'] as $action => $label): ?>
                                <form method="post" action="<?= auth_escape($returnUrl) ?>" onsubmit="return confirm('<?= $action === 'approve' ? 'Approve' : 'Remove' ?> this review?');">
                                    <input type="hidden" name="csrf_token" value="<?= auth_escape($csrfToken) ?>">
                                    <input type="hidden" name="action" value="<?= auth_escape($action) ?>">
                                    <input type="hidden" name="review_id" value="<?= (int) $review['review_id'] ?>">
                                    <button class="admin-review-action-button is-<?= auth_escape($action) ?>" type="submit"><?= auth_escape($label) ?></button>
                                </form>
                            <?php endforeach; ?>
                        <?php elseif ($status === 'Approved'): ?>
                            <form method="post" action="<?= auth_escape($returnUrl) ?>" onsubmit="return confirm('Remove this review?');">
                                <input type="hidden" name="csrf_token" value="<?= auth_escape($csrfToken) ?>">
                                <input type="hidden" name="action" value="remove">
                                <input type="hidden" name="review_id" value="<?= (int) $review['review_id'] ?>">
                                <button class="admin-review-action-button is-remove" type="submit">Remove</button>
                            </form>
                        <?php elseif ($status === 'Removed'): ?>
                            <form method="post" action="<?= auth_escape($returnUrl) ?>" onsubmit="return confirm('Restore this review?');">
                                <input type="hidden" name="csrf_token" value="<?= auth_escape($csrfToken) ?>">
                                <input type="hidden" name="action" value="restore">
                                <input type="hidden" name="review_id" value="<?= (int) $review['review_id'] ?>">
                                <button class="admin-review-action-button is-restore" type="submit">Restore</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </footer>
            </article>
        <?php endforeach; ?>
    </div>
    <?php
    return (string) ob_get_clean();
};

if ($isFragmentRequest) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'summary' => $summary,
        'statusCounts' => $statusCounts,
        'cardsHtml' => $renderReviewCards($reviews),
        'reviewLoadError' => $reviewLoadError,
        'summaryLoadError' => $summaryLoadError,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Review and moderate customer feedback.">
    <meta name="theme-color" content="#fbf8ff">
    <title>Reviews | Pawsome Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800;900&family=Nunito:wght@500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/shared/portal.css">
    <link rel="stylesheet" href="../assets/css/admin/dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin/reviews.css">
</head>
<body class="customer-app admin-app admin-reviews-page">
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

            <main class="customer-main admin-main admin-reviews-main" id="admin-main">
                <section class="customer-page-heading admin-page-heading">
                    <div>
                        <span class="eyebrow">Customer feedback</span>
                        <h1>Reviews</h1>
                        <p>Approve customer feedback before it appears publicly, or remove content that needs moderation.</p>
                    </div>
                </section>

                <?php if ($successMessage !== null): ?>
                    <p class="admin-reviews-notice" role="status"><?= auth_escape($successMessage) ?></p>
                <?php endif; ?>
                <?php if ($errorMessage !== null): ?>
                    <p class="admin-alert" role="alert"><?= auth_escape($errorMessage) ?></p>
                <?php endif; ?>

                <section class="admin-overview-grid admin-review-summary-grid" aria-label="Review summary">
                    <?php
                    $summaryCards = [
                        ['Total Reviews', 'total_reviews', 'All submitted reviews'],
                        ['Average Rating', 'average_rating', 'Approved ratings · out of 5'],
                        ['Pending Approval', 'pending_reviews', 'Waiting for moderation'],
                        ['Approved Reviews', 'approved_reviews', 'Visible to customers'],
                    ];
                    ?>
                    <?php foreach ($summaryCards as [$label, $key, $description]): ?>
                        <article class="admin-overview-card">
                            <span class="admin-overview-label"><?= auth_escape($label) ?></span>
                            <strong class="admin-overview-value" data-summary-value="<?= auth_escape($key) ?>">
                                <?php if ($summaryLoadError || $summary === null): ?>
                                    —
                                <?php elseif ($key === 'average_rating'): ?>
                                    <?= number_format((float) $summary[$key], 1) ?>
                                <?php else: ?>
                                    <?= number_format((int) $summary[$key]) ?>
                                <?php endif; ?>
                            </strong>
                            <span class="admin-overview-description"><?= auth_escape($description) ?></span>
                        </article>
                    <?php endforeach; ?>
                </section>
                <p class="admin-alert admin-review-summary-error" id="review-summary-error" role="alert"<?= $summaryLoadError ? '' : ' hidden' ?>>We couldn't load the review summary. Please try again later.</p>

                <section class="admin-panel admin-reviews-panel" aria-labelledby="reviews-list-title">
                    <div class="admin-panel-heading admin-reviews-panel-heading">
                        <div>
                            <span class="eyebrow">Review directory</span>
                            <h2 id="reviews-list-title">Review Management</h2>
                        </div>
                        <form class="admin-review-filter-form" id="review-filter-form" method="get" action="reviews.php" role="search">
                            <input type="hidden" name="status" id="review-status-filter" value="<?= auth_escape($statusFilter) ?>">
                            <div class="admin-review-filter-field admin-review-search-field">
                                <label for="review-search">Search</label>
                                <input id="review-search" name="search" type="search" maxlength="100" value="<?= auth_escape($searchTerm) ?>" placeholder="Customer, pet, service, or text">
                            </div>
                            <div class="admin-review-filter-field">
                                <label for="review-rating-filter">Rating</label>
                                <select id="review-rating-filter" name="rating">
                                    <option value="All">All ratings</option>
                                    <?php for ($ratingOption = 5; $ratingOption >= 1; $ratingOption--): ?>
                                        <option value="<?= $ratingOption ?>"<?= $ratingFilter === (string) $ratingOption ? ' selected' : '' ?>><?= $ratingOption ?> star<?= $ratingOption === 1 ? '' : 's' ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="admin-review-filter-field">
                                <label for="review-from-date">From</label>
                                <input id="review-from-date" name="from_date" type="date" value="<?= auth_escape($fromDate) ?>">
                            </div>
                            <div class="admin-review-filter-field">
                                <label for="review-to-date">To</label>
                                <input id="review-to-date" name="to_date" type="date" value="<?= auth_escape($toDate) ?>">
                            </div>
                            <button class="admin-review-filter-button" type="submit">Filter</button>
                        </form>
                    </div>

                    <nav class="admin-review-status-tabs" aria-label="Review status">
                        <?php foreach ($statusFilters as $tabStatus): ?>
                            <?php
                            $tabParameters = $queryParameters;
                            if ($tabStatus === 'All') {
                                unset($tabParameters['status']);
                            } else {
                                $tabParameters['status'] = $tabStatus;
                            }
                            $tabUrl = 'reviews.php' . ($tabParameters ? '?' . http_build_query($tabParameters) : '');
                            $tabLabel = $tabStatus === 'All' ? 'All Reviews' : $tabStatus;
                            ?>
                            <a
                                class="admin-review-status-tab<?= $statusFilter === $tabStatus ? ' is-active' : '' ?>"
                                href="<?= auth_escape($tabUrl) ?>"
                                data-review-status="<?= auth_escape($tabStatus) ?>"
                                aria-current="<?= $statusFilter === $tabStatus ? 'page' : 'false' ?>"
                            >
                                <?= auth_escape($tabLabel) ?>
                                <span data-status-count="<?= auth_escape($tabStatus) ?>"><?= number_format($statusCounts[$tabStatus]) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </nav>

                    <div class="admin-review-results" id="review-results" aria-live="polite" aria-busy="false">
                        <?php if ($reviewLoadError): ?>
                            <p class="admin-panel-message" role="alert">We couldn't load reviews right now. Please try again later.</p>
                        <?php elseif (!$reviews): ?>
                            <p class="admin-panel-message">No reviews match the selected filters.</p>
                        <?php else: ?>
                            <?= $renderReviewCards($reviews) ?>
                        <?php endif; ?>
                    </div>
                </section>
            </main>
        </div>
    </div>

    <dialog class="admin-review-dialog" id="review-dialog" aria-labelledby="review-dialog-title">
        <div class="admin-review-dialog-content">
            <header class="admin-review-dialog-header">
                <div>
                    <span class="eyebrow">Customer feedback</span>
                    <h2 id="review-dialog-title">Review Details</h2>
                </div>
                <button class="admin-review-dialog-close" id="close-review-dialog" type="button" aria-label="Close review details">&times;</button>
            </header>
            <dl class="admin-review-dialog-meta">
                <div><dt>Customer</dt><dd id="dialog-review-customer"></dd></div>
                <div><dt>Pet</dt><dd id="dialog-review-pet"></dd></div>
                <div><dt>Service</dt><dd id="dialog-review-service"></dd></div>
                <div><dt>Appointment</dt><dd id="dialog-review-appointment"></dd></div>
                <div><dt>Rating</dt><dd id="dialog-review-rating"></dd></div>
                <div><dt>Date</dt><dd id="dialog-review-date"></dd></div>
                <div><dt>Status</dt><dd id="dialog-review-status"></dd></div>
            </dl>
            <section class="admin-review-full-feedback">
                <h3>Full review</h3>
                <p id="dialog-review-feedback"></p>
            </section>
        </div>
    </dialog>

    <script>
    (() => {
        const dialog = document.getElementById('review-dialog');
        const form = document.getElementById('review-filter-form');
        const statusInput = document.getElementById('review-status-filter');
        const results = document.getElementById('review-results');
        const summaryError = document.getElementById('review-summary-error');
        let activeController = null;
        let searchTimer = null;

        const fill = (id, value) => {
            document.getElementById(id).textContent = value == null ? '' : String(value);
        };

        const refreshReviewList = async () => {
            if (activeController) activeController.abort();
            activeController = new AbortController();
            const controller = activeController;
            const requestUrl = new URL(form.action, window.location.href);
            const pageUrl = new URL(window.location.href);
            ['search', 'rating', 'status', 'from_date', 'to_date', 'fragment'].forEach((key) => {
                requestUrl.searchParams.delete(key);
                pageUrl.searchParams.delete(key);
            });
            for (const [key, value] of new FormData(form).entries()) {
                if (value !== '' && !(key === 'status' && value === 'All')) {
                    requestUrl.searchParams.set(key, value);
                    pageUrl.searchParams.set(key, value);
                }
            }
            requestUrl.searchParams.set('fragment', '1');
            results.setAttribute('aria-busy', 'true');

            try {
                const response = await fetch(requestUrl, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    signal: controller.signal
                });
                if (!response.ok) throw new Error('Review filters could not be loaded.');
                const payload = await response.json();

                if (payload.reviewLoadError) {
                    results.innerHTML = '<p class="admin-panel-message" role="alert">We couldn\'t load reviews right now. Please try again later.</p>';
                } else if (payload.cardsHtml) {
                    results.innerHTML = payload.cardsHtml;
                } else {
                    results.innerHTML = '<p class="admin-panel-message">No reviews match the selected filters.</p>';
                }

                if (payload.summaryLoadError || !payload.summary) {
                    summaryError.hidden = false;
                } else {
                    summaryError.hidden = true;
                    document.querySelectorAll('[data-summary-value]').forEach((element) => {
                        const key = element.dataset.summaryValue;
                        const value = payload.summary[key];
                        element.textContent = key === 'average_rating'
                            ? Number(value).toFixed(1)
                            : Number(value).toLocaleString();
                    });
                    Object.entries(payload.statusCounts || {}).forEach(([status, count]) => {
                        const element = document.querySelector(`[data-status-count="${status}"]`);
                        if (element) element.textContent = Number(count).toLocaleString();
                    });
                }

                document.querySelectorAll('.admin-review-status-tab').forEach((tab) => {
                    const isActive = tab.dataset.reviewStatus === (statusInput.value || 'All');
                    tab.classList.toggle('is-active', isActive);
                    if (isActive) {
                        tab.setAttribute('aria-current', 'page');
                    } else {
                        tab.removeAttribute('aria-current');
                    }
                });
                history.replaceState(null, '', pageUrl.pathname + (pageUrl.searchParams.toString() ? `?${pageUrl.searchParams}` : ''));
            } catch (error) {
                if (error.name !== 'AbortError') {
                    results.innerHTML = '<p class="admin-panel-message" role="alert">We couldn\'t load reviews right now. Please try again later.</p>';
                }
            } finally {
                if (activeController === controller) {
                    results.setAttribute('aria-busy', 'false');
                    activeController = null;
                }
            }
        };

        form.addEventListener('submit', (event) => {
            event.preventDefault();
            refreshReviewList();
        });
        form.querySelector('[name="search"]').addEventListener('input', () => {
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(refreshReviewList, 250);
        });
        form.querySelectorAll('select, input[type="date"]').forEach((input) => {
            input.addEventListener('change', refreshReviewList);
        });
        document.querySelectorAll('.admin-review-status-tab').forEach((tab) => {
            tab.addEventListener('click', (event) => {
                event.preventDefault();
                statusInput.value = tab.dataset.reviewStatus;
                refreshReviewList();
            });
        });

        document.addEventListener('click', (event) => {
            const button = event.target.closest('.admin-review-view-button');
            if (!button) return;
            try {
                const review = JSON.parse(button.dataset.review);
                fill('dialog-review-customer', review.customer);
                fill('dialog-review-pet', review.pet);
                fill('dialog-review-service', review.service);
                fill('dialog-review-appointment', `#${review.appointment}`);
                fill('dialog-review-rating', `${review.rating} / 5 stars`);
                fill('dialog-review-date', review.date);
                fill('dialog-review-status', review.status);
                fill('dialog-review-feedback', review.feedback);
                dialog.showModal();
            } catch (error) {
                window.alert('We could not open this review. Refresh the page and try again.');
            }
        });
        document.getElementById('close-review-dialog').addEventListener('click', () => dialog.close());
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) dialog.close();
        });
    })();
    </script>
</body>
</html>
