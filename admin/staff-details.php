<?php
require_once __DIR__ . '/../includes/auth.php';
auth_require_admin();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/staff_uploads.php';

$adminFirstName = (string) ($_SESSION['first_name'] ?? 'admin');
$activeAdminPage = 'staff';
$csrfToken = auth_csrf_token();
$rawStaffId = $_GET['id'] ?? '';
$staffId = is_string($rawStaffId)
    ? filter_var($rawStaffId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;
if ($staffId === false) {
    http_response_code(404);
    exit('Staff profile not found.');
}

$staff = null;
try {
    $statement = $conn->prepare('SELECT * FROM staff WHERE staff_id = ? LIMIT 1');
    $statement->bind_param('i', $staffId);
    $statement->execute();
    $staff = $statement->get_result()->fetch_assoc();
    $statement->close();
} catch (mysqli_sql_exception $exception) {
    error_log('Pawsome admin staff detail query failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('We could not load this staff profile right now.');
}
if (!$staff) {
    http_response_code(404);
    exit('Staff profile not found.');
}

$staffFormValues = [
    'first_name' => (string) $staff['first_name'],
    'last_name' => (string) $staff['last_name'],
    'position' => (string) $staff['position'],
    'contact_number' => (string) ($staff['contact_number'] ?? ''),
    'email' => (string) $staff['email'],
    'experience' => (string) ($staff['experience'] ?? ''),
    'specialization' => (string) ($staff['specialization'] ?? ''),
    'bio' => (string) ($staff['bio'] ?? ''),
    'employment_status' => (string) $staff['employment_status'],
];
$editMode = ($_GET['edit'] ?? '') === '1';
$formError = '';
$notice = ($_GET['created'] ?? '') === '1'
    ? 'Staff profile created.'
    : ((($_GET['updated'] ?? '') === '1') ? 'Staff profile updated.' : '');

$postString = static function (string $key): string {
    return isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : '';
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!auth_csrf_is_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        exit('Your session expired. Reload the page and try again.');
    }

    $action = $postString('action');
    if ($action === 'toggle_status') {
        $newStatus = $staff['employment_status'] === 'Active' ? 'Inactive' : 'Active';
        try {
            $statement = $conn->prepare('UPDATE staff SET employment_status = ? WHERE staff_id = ?');
            $statement->bind_param('si', $newStatus, $staffId);
            $statement->execute();
            $statement->close();
            header('Location: staff-details.php?id=' . $staffId . '&updated=1', true, 303);
            exit;
        } catch (mysqli_sql_exception $exception) {
            error_log('Pawsome admin staff status update failed: ' . $exception->getMessage());
            $formError = 'We could not update this staff status right now. Please try again.';
        }
    } elseif ($action === 'update') {
        $editMode = true;
        foreach (array_keys($staffFormValues) as $key) {
            $staffFormValues[$key] = $postString($key);
        }
        if (!in_array($staffFormValues['employment_status'], ['Active', 'Inactive'], true)) {
            $staffFormValues['employment_status'] = (string) $staff['employment_status'];
        }

        $email = $staffFormValues['email'];
        if (
            $staffFormValues['first_name'] === ''
            || auth_string_length($staffFormValues['first_name']) > 100
            || $staffFormValues['last_name'] === ''
            || auth_string_length($staffFormValues['last_name']) > 100
            || $staffFormValues['position'] === ''
            || auth_string_length($staffFormValues['position']) > 100
            || auth_string_length($staffFormValues['contact_number']) > 20
            || $email === ''
            || auth_string_length($email) > 150
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || auth_string_length($staffFormValues['experience']) > 150
            || auth_string_length($staffFormValues['specialization']) > 255
            || auth_string_length($staffFormValues['bio']) > 2000
        ) {
            $formError = 'Check the required fields and make sure each value is within its allowed length.';
        } else {
            $upload = staff_store_profile_photo($_FILES['profile_photo'] ?? null);
            if ($upload['error'] !== null) {
                $formError = $upload['error'];
            } else {
                $contactNumber = $staffFormValues['contact_number'] === '' ? null : $staffFormValues['contact_number'];
                $experience = $staffFormValues['experience'] === '' ? null : $staffFormValues['experience'];
                $specialization = $staffFormValues['specialization'] === '' ? null : $staffFormValues['specialization'];
                $bio = $staffFormValues['bio'] === '' ? null : $staffFormValues['bio'];
                $newPhotoPath = $upload['filename'] ?? $staff['profile_photo_path'];

                try {
                    $statement = $conn->prepare(
                        'UPDATE staff
                         SET first_name = ?, last_name = ?, position = ?, contact_number = ?,
                             email = ?, experience = ?, specialization = ?, bio = ?,
                             employment_status = ?, profile_photo_path = ?
                         WHERE staff_id = ?'
                    );
                    $statement->bind_param(
                        'ssssssssssi',
                        $staffFormValues['first_name'],
                        $staffFormValues['last_name'],
                        $staffFormValues['position'],
                        $contactNumber,
                        $email,
                        $experience,
                        $specialization,
                        $bio,
                        $staffFormValues['employment_status'],
                        $newPhotoPath,
                        $staffId
                    );
                    $statement->execute();
                    $statement->close();

                    if (
                        $upload['filename'] !== null
                        && !empty($staff['profile_photo_path'])
                        && !staff_remove_profile_photo($staff['profile_photo_path'])
                    ) {
                        error_log('Pawsome admin could not remove replaced staff profile photo.');
                    }
                    header('Location: staff-details.php?id=' . $staffId . '&updated=1', true, 303);
                    exit;
                } catch (mysqli_sql_exception $exception) {
                    error_log('Pawsome admin staff update failed: ' . $exception->getMessage());
                    if ($upload['filename'] !== null && !staff_remove_profile_photo($upload['filename'])) {
                        error_log('Pawsome admin staff photo cleanup failed after update error.');
                    }
                    $formError = 'We could not save this staff profile right now. Please try again.';
                }
            }
        }
    } else {
        http_response_code(400);
        exit('Choose a valid staff action.');
    }
}

$fullName = trim((string) $staff['first_name'] . ' ' . (string) $staff['last_name']);
$hasPhoto = !empty($staff['profile_photo_path']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="View and manage a Pawsome staff profile.">
    <meta name="theme-color" content="#fbf8ff">
    <title><?= auth_escape($fullName) ?> | Pawsome Staff</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800;900&family=Nunito:wght@500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/shared/portal.css">
    <link rel="stylesheet" href="../assets/css/admin/dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin/staff.css">
</head>
<body class="customer-app admin-app admin-staff-page">
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

            <main class="customer-main admin-main admin-staff-detail-main" id="admin-main">
                <a class="admin-staff-back" href="staff.php">&larr; Back to Staff</a>
                <section class="customer-page-heading admin-page-heading admin-staff-detail-heading">
                    <div>
                        <span class="eyebrow">Staff profile</span>
                        <h1><?= auth_escape($fullName) ?> <span>#<?= (int) $staffId ?></span></h1>
                        <p><?= auth_escape((string) $staff['position']) ?></p>
                    </div>
                    <?php if (!$editMode): ?>
                        <div class="admin-staff-detail-actions">
                            <a class="admin-staff-edit-button" href="staff-details.php?id=<?= (int) $staffId ?>&amp;edit=1">Edit Staff</a>
                            <form method="post" action="staff-details.php?id=<?= (int) $staffId ?>">
                                <input type="hidden" name="csrf_token" value="<?= auth_escape($csrfToken) ?>">
                                <input type="hidden" name="action" value="toggle_status">
                                <button
                                    class="admin-staff-toggle-button <?= $staff['employment_status'] === 'Active' ? 'is-deactivate' : 'is-activate' ?>"
                                    type="submit"
                                    data-confirm="<?= $staff['employment_status'] === 'Active' ? 'Deactivate' : 'Activate' ?> this staff profile?"
                                ><?= $staff['employment_status'] === 'Active' ? 'Deactivate Staff' : 'Activate Staff' ?></button>
                            </form>
                        </div>
                    <?php endif; ?>
                </section>

                <?php if ($notice !== ''): ?>
                    <p class="admin-staff-notice" role="status"><?= auth_escape($notice) ?></p>
                <?php endif; ?>
                <?php if ($formError !== ''): ?>
                    <p class="admin-alert" role="alert"><?= auth_escape($formError) ?></p>
                <?php endif; ?>

                <?php if ($editMode): ?>
                    <section class="admin-panel admin-staff-edit-panel" aria-labelledby="edit-staff-title">
                        <div class="admin-panel-heading">
                            <div>
                                <span class="eyebrow">Team directory</span>
                                <h2 id="edit-staff-title">Edit Staff</h2>
                            </div>
                            <a class="admin-staff-cancel-link" href="staff-details.php?id=<?= (int) $staffId ?>">Cancel</a>
                        </div>
                        <form class="admin-staff-edit-form" method="post" action="staff-details.php?id=<?= (int) $staffId ?>&amp;edit=1" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="<?= auth_escape($csrfToken) ?>">
                            <input type="hidden" name="action" value="update">
                            <?php if ($hasPhoto): ?>
                                <div class="admin-staff-current-photo">
                                    <img src="staff-photo.php?id=<?= (int) $staffId ?>" alt="<?= auth_escape($fullName) ?>">
                                    <span>Current profile photo</span>
                                </div>
                            <?php endif; ?>
                            <?php require __DIR__ . '/../includes/admin_staff_form_fields.php'; ?>
                            <footer class="admin-staff-form-actions">
                                <a class="admin-staff-cancel-link" href="staff-details.php?id=<?= (int) $staffId ?>">Cancel</a>
                                <button class="button button-primary" type="submit">Save Changes</button>
                            </footer>
                        </form>
                    </section>
                <?php else: ?>
                    <section class="admin-panel admin-staff-profile-panel" aria-labelledby="staff-profile-title">
                        <div class="admin-panel-heading">
                            <div>
                                <span class="eyebrow">Team directory</span>
                                <h2 id="staff-profile-title">Staff Information</h2>
                            </div>
                            <span class="admin-staff-status <?= $staff['employment_status'] === 'Active' ? 'is-active' : 'is-inactive' ?>">
                                <?= auth_escape((string) $staff['employment_status']) ?>
                            </span>
                        </div>
                        <div class="admin-staff-profile-layout">
                            <?php if ($hasPhoto): ?>
                                <img class="admin-staff-detail-photo" src="staff-photo.php?id=<?= (int) $staffId ?>" alt="<?= auth_escape($fullName) ?>">
                            <?php else: ?>
                                <div class="admin-staff-detail-photo admin-staff-photo-placeholder" aria-label="No profile photo">
                                    <?= auth_escape(strtoupper(substr((string) $staff['first_name'], 0, 1))) ?>
                                </div>
                            <?php endif; ?>
                            <dl class="admin-staff-profile-list">
                                <div><dt>First name</dt><dd><?= auth_escape((string) $staff['first_name']) ?></dd></div>
                                <div><dt>Last name</dt><dd><?= auth_escape((string) $staff['last_name']) ?></dd></div>
                                <div><dt>Position</dt><dd><?= auth_escape((string) $staff['position']) ?></dd></div>
                                <div><dt>Contact number</dt><dd><?= auth_escape((string) ($staff['contact_number'] ?: 'Not provided')) ?></dd></div>
                                <div><dt>Email</dt><dd><a href="mailto:<?= auth_escape((string) $staff['email']) ?>"><?= auth_escape((string) $staff['email']) ?></a></dd></div>
                                <div><dt>Experience</dt><dd><?= auth_escape((string) ($staff['experience'] ?: 'Not provided')) ?></dd></div>
                                <div><dt>Specialization</dt><dd><?= auth_escape((string) ($staff['specialization'] ?: 'Not provided')) ?></dd></div>
                                <div><dt>Employment status</dt><dd><?= auth_escape((string) $staff['employment_status']) ?></dd></div>
                                <div class="admin-staff-bio-item"><dt>Short bio</dt><dd><?= nl2br(auth_escape((string) ($staff['bio'] ?: 'No bio provided.'))) ?></dd></div>
                            </dl>
                        </div>
                    </section>
                <?php endif; ?>
            </main>
        </div>
    </div>
    <?php if (!$editMode): ?>
        <script>
        document.querySelectorAll('[data-confirm]').forEach((button) => {
            button.closest('form').addEventListener('submit', (event) => {
                if (!window.confirm(button.dataset.confirm)) event.preventDefault();
            });
        });
        </script>
    <?php endif; ?>
</body>
</html>
