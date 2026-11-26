<?php
require_once __DIR__ . '/../includes/auth.php';
auth_require_customer();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/activity_log.php';
require_once __DIR__ . '/../includes/admin_notifications.php';

$pageTitle = 'My Reviews';
$activeCustomerPage = 'reviews';
$customerId = (int) $_SESSION['user_id'];
$csrfToken = auth_csrf_token();
$formMode = '';
$editingReview = null;
$eligibleAppointment = null;
$formError = '';
$formValues = ['rating' => '', 'feedback' => ''];
$notice = ($_GET['saved'] ?? '') === '1'
    ? 'Your review was submitted and is waiting for approval.'
    : ((($_GET['updated'] ?? '') === '1') ? 'Your review was updated and returned to Pending approval.' : '');
$reviewLoadError = false;
$eligibleLoadError = false;
$reviews = [];
$eligibleAppointments = [];

$postString = static function (string $key): string {
    return isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : '';
};

$rawEditId = $_GET['edit'] ?? '';
$rawAppointmentId = $_GET['appointment_id'] ?? '';
$editId = $rawEditId === ''
    ? null
    : (is_string($rawEditId)
        ? filter_var($rawEditId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
        : false);
$requestedAppointmentId = $rawAppointmentId === ''
    ? null
    : (is_string($rawAppointmentId)
        ? filter_var($rawAppointmentId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
        : false);

if ($editId === false || $requestedAppointmentId === false || ($editId !== null && $requestedAppointmentId !== null)) {
    http_response_code(400);
    exit('Choose a valid review.');
}

if ($editId !== null) {
    try {
        $statement = $conn->prepare(
            'SELECT r.review_id, r.appointment_id, r.rating, r.feedback, r.status AS review_status,
                    a.status AS appointment_status, a.appointment_date, a.appointment_time,
                    p.pet_name, s.service_name
             FROM reviews AS r
             INNER JOIN appointments AS a
                ON a.appointment_id = r.appointment_id AND a.user_id = r.user_id
             INNER JOIN pets AS p ON p.pet_id = a.pet_id AND p.user_id = a.user_id
             INNER JOIN services AS s ON s.service_id = a.service_id
             WHERE r.review_id = ? AND r.user_id = ?
             LIMIT 1'
        );
        $statement->bind_param('ii', $editId, $customerId);
        $statement->execute();
        $editingReview = $statement->get_result()->fetch_assoc();
        $statement->close();
    } catch (mysqli_sql_exception $exception) {
        error_log('Pawsome customer review edit lookup failed: ' . $exception->getMessage());
        $formError = 'We could not load this review right now. Please try again.';
    }

    if (!$editingReview && $formError === '') {
        http_response_code(404);
        exit('Review not found.');
    }
    if ($editingReview && $editingReview['appointment_status'] === 'Completed') {
        $formMode = 'edit';
        $formValues = [
            'rating' => (string) $editingReview['rating'],
            'feedback' => (string) $editingReview['feedback'],
        ];
    } elseif ($editingReview) {
        $formError = 'Only reviews for your completed appointments can be edited.';
    }
} elseif ($requestedAppointmentId !== null) {
    try {
        $statement = $conn->prepare(
            "SELECT a.appointment_id, a.appointment_date, a.appointment_time,
                    p.pet_name, s.service_name
             FROM appointments AS a
             INNER JOIN pets AS p ON p.pet_id = a.pet_id AND p.user_id = a.user_id
             INNER JOIN services AS s ON s.service_id = a.service_id
             WHERE a.appointment_id = ? AND a.user_id = ? AND a.status = 'Completed'
               AND NOT EXISTS (
                    SELECT 1 FROM reviews AS r WHERE r.appointment_id = a.appointment_id
               )
             LIMIT 1"
        );
        $statement->bind_param('ii', $requestedAppointmentId, $customerId);
        $statement->execute();
        $eligibleAppointment = $statement->get_result()->fetch_assoc();
        $statement->close();
    } catch (mysqli_sql_exception $exception) {
        error_log('Pawsome customer eligible review lookup failed: ' . $exception->getMessage());
        $formError = 'We could not load this completed appointment right now. Please try again.';
    }

    if ($eligibleAppointment) {
        $formMode = 'create';
    } elseif ($formError === '') {
        $formError = 'Only your completed appointments without an existing review can be reviewed.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!auth_csrf_is_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        exit('Your session expired. Reload the page and try again.');
    }
    if ($postString('action') !== 'save' || $formMode === '') {
        http_response_code(400);
        exit('Choose a valid review action.');
    }

    $formValues['rating'] = $postString('rating');
    $formValues['feedback'] = $postString('feedback');
    $rating = filter_var($formValues['rating'], FILTER_VALIDATE_INT);
    $feedback = $formValues['feedback'];
    $rawPostedReviewId = $postString('review_id');
    $rawPostedAppointmentId = $postString('appointment_id');

    if (
        $rating === false
        || $rating < 1
        || $rating > 5
        || $feedback === ''
        || auth_string_length($feedback) > 2000
    ) {
        $formError = 'Choose a 1–5 star rating and enter feedback up to 2,000 characters.';
    } elseif (
        ($formMode === 'edit' && $rawPostedReviewId !== (string) $editingReview['review_id'])
        || ($formMode === 'create'
            && (
                $rawPostedReviewId !== ''
                || !is_string($rawPostedAppointmentId)
                || filter_var($rawPostedAppointmentId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== $requestedAppointmentId
            ))
    ) {
        http_response_code(400);
        exit('The review form has expired. Reload the page and try again.');
    } else {
        try {
            $conn->begin_transaction();

            if ($formMode === 'edit') {
                $reviewId = (int) $editingReview['review_id'];
                $statement = $conn->prepare(
                    'SELECT appointment_id
                     FROM reviews
                     WHERE review_id = ? AND user_id = ?
                     FOR UPDATE'
                );
                $statement->bind_param('ii', $reviewId, $customerId);
                $statement->execute();
                $reviewRow = $statement->get_result()->fetch_assoc();
                $statement->close();
                if (!$reviewRow) {
                    $conn->rollback();
                    $formError = 'This review could not be found under your account.';
                } else {
                    $appointmentId = (int) $reviewRow['appointment_id'];
                }
            } else {
                $appointmentId = (int) $requestedAppointmentId;
            }

            if ($formError === '') {
                $statement = $conn->prepare(
                    "SELECT appointment_id
                     FROM appointments
                     WHERE appointment_id = ? AND user_id = ? AND status = 'Completed'
                     FOR UPDATE"
                );
                $statement->bind_param('ii', $appointmentId, $customerId);
                $statement->execute();
                $completedAppointment = $statement->get_result()->fetch_assoc();
                $statement->close();
                if (!$completedAppointment) {
                    $conn->rollback();
                    $formError = 'Reviews are available only for your completed appointments.';
                } elseif ($formMode === 'edit') {
                    $statement = $conn->prepare(
                        'UPDATE reviews
                         SET rating = ?, feedback = ?, status = \'Pending\',
                             status_before_removal = NULL, is_hidden = 1
                         WHERE review_id = ? AND user_id = ?'
                    );
                    $statement->bind_param('isii', $rating, $feedback, $reviewId, $customerId);
                    $statement->execute();
                    $statement->close();
                    activity_log_current_user(
                        $conn,
                        'review.updated',
                        'Updated review #' . $reviewId . '.',
                        [
                            'review_id' => $reviewId,
                            'appointment_id' => $appointmentId,
                            'rating' => $rating,
                            'status' => 'Pending',
                        ]
                    );
                    $conn->commit();
                    header('Location: reviews.php?updated=1', true, 303);
                    exit;
                } else {
                    $statement = $conn->prepare(
                        'INSERT INTO reviews (appointment_id, user_id, rating, feedback, status, is_hidden)
                         VALUES (?, ?, ?, ?, \'Pending\', 1)'
                    );
                    $statement->bind_param('iiis', $appointmentId, $customerId, $rating, $feedback);
                    $statement->execute();
                    $reviewId = (int) $conn->insert_id;
                    $statement->close();
                    activity_log_current_user(
                        $conn,
                        'review.submitted',
                        'Submitted review #' . $reviewId . '.',
                        [
                            'review_id' => $reviewId,
                            'appointment_id' => $appointmentId,
                            'rating' => $rating,
                            'status' => 'Pending',
                        ]
                    );
                    admin_notification_broadcast(
                        $conn,
                        'review.submitted',
                        'New customer review',
                        'A customer submitted a review for appointment #' . $appointmentId . '.',
                        'reviews.php'
                    );
                    $conn->commit();
                    header('Location: reviews.php?saved=1', true, 303);
                    exit;
                }
            }
        } catch (mysqli_sql_exception $exception) {
            $conn->rollback();
            error_log('Pawsome customer review save failed: ' . $exception->getMessage());
            $formError = $exception->getCode() === 1062
                ? 'A review already exists for this appointment. You can edit it from My Reviews.'
                : 'We could not save your review right now. Please try again.';
        }
    }
}

try {
    $statement = $conn->prepare(
        'SELECT r.review_id, r.appointment_id, r.rating, r.feedback, r.status AS review_status,
                r.created_at, r.updated_at, a.status AS appointment_status, a.appointment_date,
                p.pet_name, s.service_name
         FROM reviews AS r
         INNER JOIN appointments AS a
            ON a.appointment_id = r.appointment_id AND a.user_id = r.user_id
         INNER JOIN pets AS p ON p.pet_id = a.pet_id AND p.user_id = a.user_id
         INNER JOIN services AS s ON s.service_id = a.service_id
         WHERE r.user_id = ?
         ORDER BY r.updated_at DESC, r.review_id DESC'
    );
    $statement->bind_param('i', $customerId);
    $statement->execute();
    $reviews = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
} catch (mysqli_sql_exception $exception) {
    error_log('Pawsome customer reviews list query failed: ' . $exception->getMessage());
    $reviewLoadError = true;
}

try {
    $statement = $conn->prepare(
        "SELECT a.appointment_id, a.appointment_date, a.appointment_time,
                p.pet_name, s.service_name
         FROM appointments AS a
         INNER JOIN pets AS p ON p.pet_id = a.pet_id AND p.user_id = a.user_id
         INNER JOIN services AS s ON s.service_id = a.service_id
         WHERE a.user_id = ? AND a.status = 'Completed'
           AND NOT EXISTS (
                SELECT 1 FROM reviews AS r WHERE r.appointment_id = a.appointment_id
           )
         ORDER BY a.appointment_date DESC, a.appointment_time DESC, a.appointment_id DESC"
    );
    $statement->bind_param('i', $customerId);
    $statement->execute();
    $eligibleAppointments = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
} catch (mysqli_sql_exception $exception) {
    error_log('Pawsome customer eligible reviews query failed: ' . $exception->getMessage());
    $eligibleLoadError = true;
}

require __DIR__ . '/../includes/customer_header.php';
?>
<section class="customer-page-heading customer-reviews-heading">
    <span class="eyebrow">Your feedback</span>
    <h1>My <span>Reviews</span></h1>
    <p>Review completed appointments and update your feedback. New and edited reviews are held for approval before being shown publicly.</p>
</section>

<?php if ($notice !== ''): ?>
    <p class="customer-success" role="status"><?= auth_escape($notice) ?></p>
<?php endif; ?>
<?php if ($formError !== ''): ?>
    <p class="customer-alert" role="alert"><?= auth_escape($formError) ?></p>
<?php endif; ?>
<?php if ($reviewLoadError): ?>
    <p class="customer-alert" role="alert">We couldn't load your reviews right now. Please try again later.</p>
<?php endif; ?>

<?php if ($formMode !== ''): ?>
    <?php
    $formAppointment = $formMode === 'edit' ? $editingReview : $eligibleAppointment;
    $formAppointmentId = (int) $formAppointment['appointment_id'];
    $formHeading = $formMode === 'edit' ? 'Edit Your Review' : 'Review Your Appointment';
    ?>
    <section class="customer-review-form-card" aria-labelledby="review-form-heading">
        <div class="customer-review-form-heading">
            <div>
                <span class="eyebrow"><?= $formMode === 'edit' ? 'Update your feedback' : 'Completed appointment' ?></span>
                <h2 id="review-form-heading"><?= auth_escape($formHeading) ?></h2>
                <p><?= auth_escape((string) $formAppointment['pet_name']) ?> · <?= auth_escape((string) $formAppointment['service_name']) ?> · <?= auth_escape(date('M j, Y', strtotime((string) $formAppointment['appointment_date']))) ?></p>
                <?php if ($formMode === 'edit'): ?>
                    <p class="customer-review-form-status">Current status: <?= auth_escape((string) $editingReview['review_status']) ?>. Saving changes will return it to Pending approval.</p>
                <?php endif; ?>
            </div>
            <a class="customer-review-cancel" href="reviews.php">Cancel</a>
        </div>
        <form class="customer-review-form" method="post" action="<?= $formMode === 'edit' ? 'reviews.php?edit=' . (int) $editingReview['review_id'] : 'reviews.php?appointment_id=' . $formAppointmentId ?>">
            <input type="hidden" name="csrf_token" value="<?= auth_escape($csrfToken) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="review_id" value="<?= $formMode === 'edit' ? (int) $editingReview['review_id'] : '' ?>">
            <input type="hidden" name="appointment_id" value="<?= $formMode === 'create' ? $formAppointmentId : '' ?>">
            <fieldset class="customer-review-rating">
                <legend>Your rating</legend>
                <div class="customer-review-rating-options">
                    <?php for ($ratingOption = 1; $ratingOption <= 5; $ratingOption++): ?>
                        <label class="customer-review-rating-option" for="review-rating-<?= $ratingOption ?>">
                            <input
                                id="review-rating-<?= $ratingOption ?>"
                                name="rating"
                                type="radio"
                                value="<?= $ratingOption ?>"
                                <?= $formValues['rating'] === (string) $ratingOption ? 'checked' : '' ?>
                                required
                            >
                            <span><?= $ratingOption ?> <span aria-hidden="true">★</span></span>
                        </label>
                    <?php endfor; ?>
                </div>
            </fieldset>
            <div class="customer-review-feedback-field">
                <label for="review-feedback">Your feedback</label>
                <textarea id="review-feedback" name="feedback" rows="5" maxlength="2000" required><?= auth_escape($formValues['feedback']) ?></textarea>
                <span>Share your experience in up to 2,000 characters.</span>
            </div>
            <div class="customer-review-form-actions">
                <button class="button button-primary" type="submit"><?= $formMode === 'edit' ? 'Save Changes' : 'Submit Review' ?></button>
            </div>
        </form>
    </section>
<?php endif; ?>

<section class="customer-review-section" aria-labelledby="eligible-reviews-heading">
    <div class="customer-review-section-heading">
        <div>
            <span class="eyebrow">Your completed visits</span>
            <h2 id="eligible-reviews-heading">Appointments to Review</h2>
        </div>
    </div>
    <?php if ($eligibleLoadError): ?>
        <p class="customer-alert" role="alert">We couldn't load completed appointments right now. Please try again later.</p>
    <?php elseif (!$eligibleAppointments): ?>
        <div class="customer-empty-state customer-review-empty">
            <span class="customer-empty-mark" aria-hidden="true">★</span>
            <h2>No appointments to review</h2>
            <p>Completed appointments without a review will appear here.</p>
        </div>
    <?php else: ?>
        <div class="customer-review-eligible-list">
            <?php foreach ($eligibleAppointments as $appointment): ?>
                <article class="customer-review-eligible-card">
                    <div>
                        <h3><?= auth_escape((string) $appointment['pet_name']) ?> · <?= auth_escape((string) $appointment['service_name']) ?></h3>
                        <p>Appointment #<?= (int) $appointment['appointment_id'] ?> · <?= auth_escape(date('M j, Y', strtotime((string) $appointment['appointment_date']))) ?></p>
                    </div>
                    <a class="customer-review-action" href="reviews.php?appointment_id=<?= (int) $appointment['appointment_id'] ?>">Write Review</a>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="customer-review-section" aria-labelledby="my-reviews-heading">
    <div class="customer-review-section-heading">
        <div>
            <span class="eyebrow">Your submitted feedback</span>
            <h2 id="my-reviews-heading">My Reviews</h2>
        </div>
    </div>
    <?php if ($reviewLoadError): ?>
        <p class="customer-alert" role="alert">We couldn't load your reviews right now. Please try again later.</p>
    <?php elseif (!$reviews): ?>
        <div class="customer-empty-state customer-review-empty">
            <span class="customer-empty-mark" aria-hidden="true">♡</span>
            <h2>No reviews yet</h2>
            <p>After you review a completed appointment, it will be saved here.</p>
        </div>
    <?php else: ?>
        <div class="customer-review-list">
            <?php foreach ($reviews as $review): ?>
                <?php
                $reviewDate = date('M j, Y', strtotime((string) $review['updated_at']));
                $reviewRating = (int) $review['rating'];
                $reviewStatus = (string) $review['review_status'];
                ?>
                <article class="customer-review-card">
                    <header class="customer-review-card-header">
                        <div>
                            <h3><?= auth_escape((string) $review['pet_name']) ?> · <?= auth_escape((string) $review['service_name']) ?></h3>
                            <p>Appointment #<?= (int) $review['appointment_id'] ?> · <?= auth_escape(date('M j, Y', strtotime((string) $review['appointment_date']))) ?></p>
                        </div>
                        <div class="customer-review-card-actions">
                            <span class="customer-review-status is-<?= auth_escape(strtolower($reviewStatus)) ?>"><?= auth_escape($reviewStatus) ?></span>
                            <?php if ($review['appointment_status'] === 'Completed'): ?>
                                <a class="customer-review-edit" href="reviews.php?edit=<?= (int) $review['review_id'] ?>">Edit</a>
                            <?php endif; ?>
                        </div>
                    </header>
                    <div class="customer-review-stars" aria-label="<?= $reviewRating ?> out of 5 stars">
                        <?= str_repeat('★', $reviewRating) ?><span><?= str_repeat('☆', 5 - $reviewRating) ?></span>
                    </div>
                    <p class="customer-review-card-feedback"><?= nl2br(auth_escape((string) $review['feedback'])) ?></p>
                    <?php if ($reviewStatus !== 'Approved'): ?>
                        <p class="customer-review-status-note">Your review is private until it is approved.</p>
                    <?php endif; ?>
                    <p class="customer-review-card-date">Updated <?= auth_escape($reviewDate) ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/customer_footer.php'; ?>
