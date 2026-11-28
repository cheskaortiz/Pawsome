<?php
require_once __DIR__ . '/../includes/auth.php';
auth_require_customer();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/activity_log.php';

$pageTitle = 'My Profile';
$activeCustomerPage = 'profile';
$customerId = (int) $_SESSION['user_id'];
$dialogRequest = (string) ($_GET['dialog'] ?? $_POST['dialog'] ?? '') === '1';
$sendDialogJson = static function (array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
};
$profileValues = [
    'first_name' => (string) ($_SESSION['first_name'] ?? ''),
    'last_name' => (string) ($_SESSION['last_name'] ?? ''),
    'email' => '',
    'username' => (string) ($_SESSION['username'] ?? ''),
    'phone' => '',
];
$profileLoadError = false;
$profileNotFound = false;
$errorMessage = '';
$successMessage = auth_take_flash('customer_profile_success');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!auth_csrf_is_valid($_POST['csrf_token'] ?? null)) {
        if ($dialogRequest) {
            $sendDialogJson(['error' => 'Your session expired. Reload the page and try again.'], 400);
        }
        http_response_code(400);
        exit('Your session expired. Reload the page and try again.');
    }

    foreach (array_keys($profileValues) as $field) {
        $profileValues[$field] = trim(auth_post_string($field));
    }

    $validProfile = $profileValues['first_name'] !== ''
        && auth_string_length($profileValues['first_name']) <= 100
        && $profileValues['last_name'] !== ''
        && auth_string_length($profileValues['last_name']) <= 100
        && $profileValues['email'] !== ''
        && auth_string_length($profileValues['email']) <= 150
        && filter_var($profileValues['email'], FILTER_VALIDATE_EMAIL) !== false
        && $profileValues['username'] !== ''
        && auth_string_length($profileValues['username']) <= 50
        && auth_string_length($profileValues['phone']) <= 20;

    if (!$validProfile) {
        $errorMessage = 'Check the required fields, email address, and allowed field lengths.';
        if ($dialogRequest) {
            $sendDialogJson(['error' => $errorMessage], 422);
        }
    } else {
        $transactionStarted = false;
        try {
            $conn->begin_transaction();
            $transactionStarted = true;
            $statement = $conn->prepare(
                'SELECT first_name, last_name, email, username, phone
                 FROM users
                 WHERE user_id = ? AND role = \'customer\'
                 FOR UPDATE'
            );
            $statement->bind_param('i', $customerId);
            $statement->execute();
            $currentProfile = $statement->get_result()->fetch_assoc();
            $statement->close();

            if (!$currentProfile) {
                $conn->rollback();
                $transactionStarted = false;
                if ($dialogRequest) {
                    $sendDialogJson(['error' => 'Your customer profile could not be found.'], 404);
                }
                http_response_code(404);
                exit('Your customer profile could not be found.');
            }

            $phone = $profileValues['phone'] === '' ? null : $profileValues['phone'];
            $changedFields = [];
            foreach (['first_name', 'last_name', 'email', 'username'] as $field) {
                if ((string) $currentProfile[$field] !== $profileValues[$field]) {
                    $changedFields[] = $field;
                }
            }
            if ((string) ($currentProfile['phone'] ?? '') !== (string) ($phone ?? '')) {
                $changedFields[] = 'phone';
            }

            if ($changedFields !== []) {
                $statement = $conn->prepare(
                    'UPDATE users
                     SET first_name = ?, last_name = ?, email = ?, username = ?, phone = ?
                     WHERE user_id = ? AND role = \'customer\''
                );
                $statement->bind_param(
                    'sssssi',
                    $profileValues['first_name'],
                    $profileValues['last_name'],
                    $profileValues['email'],
                    $profileValues['username'],
                    $phone,
                    $customerId
                );
                $statement->execute();
                $statement->close();
                activity_log_current_user(
                    $conn,
                    'account.updated',
                    'Updated their customer profile.',
                    ['account_id' => $customerId, 'changed_fields' => $changedFields]
                );
            }

            $conn->commit();
            $transactionStarted = false;
            $_SESSION['first_name'] = $profileValues['first_name'];
            $_SESSION['last_name'] = $profileValues['last_name'];
            $_SESSION['username'] = $profileValues['username'];
            if ($dialogRequest) {
                $sendDialogJson([
                    'message' => 'Your profile was saved.',
                    'profile' => $profileValues,
                ]);
            }
            auth_set_flash('customer_profile_success', 'Your profile was saved.');
            header('Location: profile.php', true, 303);
            exit;
        } catch (mysqli_sql_exception $exception) {
            if ($transactionStarted) {
                $conn->rollback();
            }
            error_log('Pawsome customer profile update failed: ' . $exception->getMessage());
            $errorMessage = (int) $exception->getCode() === 1062
                ? 'That email address or username is already in use.'
                : 'We could not save your profile right now. Please try again.';
            if ($dialogRequest) {
                $sendDialogJson(
                    ['error' => $errorMessage],
                    (int) $exception->getCode() === 1062 ? 409 : 500
                );
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    try {
        $statement = $conn->prepare(
            'SELECT first_name, last_name, email, username, phone
             FROM users
             WHERE user_id = ? AND role = \'customer\'
             LIMIT 1'
        );
        $statement->bind_param('i', $customerId);
        $statement->execute();
        $profile = $statement->get_result()->fetch_assoc();
        $statement->close();
        if ($profile) {
            foreach (array_keys($profileValues) as $field) {
                $profileValues[$field] = (string) ($profile[$field] ?? '');
            }
        } else {
            $profileLoadError = true;
            $profileNotFound = true;
        }
    } catch (mysqli_sql_exception $exception) {
        error_log('Pawsome customer profile lookup failed: ' . $exception->getMessage());
        $profileLoadError = true;
    }
}

if ($dialogRequest) {
    if ($profileNotFound) {
        $sendDialogJson(['error' => 'Your customer profile could not be found.'], 404);
    }
    if ($profileLoadError) {
        $sendDialogJson(['error' => 'We could not load your profile right now. Please try again later.'], 500);
    }
    $sendDialogJson(['profile' => $profileValues]);
}

require __DIR__ . '/../includes/customer_header.php';
?>
<section class="customer-page-heading">
    <span class="eyebrow">Your account</span>
    <h1>My <span>Profile</span></h1>
    <p>Keep your account details up to date.</p>
</section>

<?php if ($successMessage !== null): ?>
    <p class="customer-success" role="status"><?= auth_escape($successMessage) ?></p>
<?php endif; ?>
<?php if ($errorMessage !== ''): ?>
    <p class="customer-alert" role="alert"><?= auth_escape($errorMessage) ?></p>
<?php endif; ?>
<?php if ($profileLoadError): ?>
    <p class="customer-alert" role="alert">We couldn't load your profile right now. Please try again later.</p>
<?php else: ?>
    <section class="customer-profile-card" aria-label="Profile details">
        <form class="customer-profile-form" method="post" action="profile.php">
            <input type="hidden" name="csrf_token" value="<?= auth_escape(auth_csrf_token()) ?>">
            <div class="customer-profile-form-grid">
                <div class="customer-profile-field">
                    <label for="profile-first-name">First name</label>
                    <input id="profile-first-name" name="first_name" type="text" maxlength="100" autocomplete="given-name" value="<?= auth_escape($profileValues['first_name']) ?>" required>
                </div>
                <div class="customer-profile-field">
                    <label for="profile-last-name">Last name</label>
                    <input id="profile-last-name" name="last_name" type="text" maxlength="100" autocomplete="family-name" value="<?= auth_escape($profileValues['last_name']) ?>" required>
                </div>
                <div class="customer-profile-field">
                    <label for="profile-email">Email address</label>
                    <input id="profile-email" name="email" type="email" maxlength="150" autocomplete="email" value="<?= auth_escape($profileValues['email']) ?>" required>
                </div>
                <div class="customer-profile-field">
                    <label for="profile-username">Username</label>
                    <input id="profile-username" name="username" type="text" maxlength="50" autocomplete="username" value="<?= auth_escape($profileValues['username']) ?>" required>
                </div>
                <div class="customer-profile-field">
                    <label for="profile-phone">Phone number</label>
                    <input id="profile-phone" name="phone" type="tel" maxlength="20" autocomplete="tel" value="<?= auth_escape($profileValues['phone']) ?>">
                </div>
            </div>
            <div class="customer-profile-actions">
                <button class="button button-primary" type="submit">Save Profile</button>
            </div>
        </form>
    </section>
<?php endif; ?>
<?php require __DIR__ . '/../includes/customer_footer.php'; ?>
