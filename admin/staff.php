<?php
require_once __DIR__ . '/../includes/auth.php';
auth_require_admin();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/staff_uploads.php';

$adminFirstName = (string) ($_SESSION['first_name'] ?? 'admin');
$activeAdminPage = 'staff';
$csrfToken = auth_csrf_token();
$searchTerm = $_GET['search'] ?? '';
$staffFormValues = [
    'first_name' => '',
    'last_name' => '',
    'position' => '',
    'contact_number' => '',
    'email' => '',
    'experience' => '',
    'specialization' => '',
    'bio' => '',
    'employment_status' => 'Active',
];
$formError = '';
$openAddDialog = false;

$postString = static function (string $key): string {
    return isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : '';
};

if (!is_string($searchTerm) || auth_string_length(trim($searchTerm)) > 100) {
    http_response_code(400);
    exit('Enter a valid staff search.');
}
$searchTerm = trim($searchTerm);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!auth_csrf_is_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        exit('Your session expired. Reload the page and try again.');
    }
    if ($postString('action') !== 'create') {
        http_response_code(400);
        exit('Choose a valid staff action.');
    }

    $openAddDialog = true;
    foreach ($staffFormValues as $key => $defaultValue) {
        $staffFormValues[$key] = $postString($key);
    }

    $email = $staffFormValues['email'];
    $staffFormValues['employment_status'] = in_array($staffFormValues['employment_status'], ['Active', 'Inactive'], true)
        ? $staffFormValues['employment_status']
        : 'Active';

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
            $photoPath = $upload['filename'];

            try {
                $statement = $conn->prepare(
                    'INSERT INTO staff
                        (first_name, last_name, position, contact_number, email,
                         experience, specialization, bio, employment_status, profile_photo_path)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $statement->bind_param(
                    'ssssssssss',
                    $staffFormValues['first_name'],
                    $staffFormValues['last_name'],
                    $staffFormValues['position'],
                    $contactNumber,
                    $email,
                    $experience,
                    $specialization,
                    $bio,
                    $staffFormValues['employment_status'],
                    $photoPath
                );
                $statement->execute();
                $staffId = (int) $statement->insert_id;
                $statement->close();
                header('Location: staff-details.php?id=' . $staffId . '&created=1', true, 303);
                exit;
            } catch (mysqli_sql_exception $exception) {
                error_log('Pawsome admin staff creation failed: ' . $exception->getMessage());
                if ($photoPath !== null && !staff_remove_profile_photo($photoPath)) {
                    error_log('Pawsome admin staff photo cleanup failed after create error.');
                }
                $formError = 'We could not save this staff profile right now. Please try again.';
            }
        }
    }
}

$summary = null;
$staffMembers = [];
$summaryLoadError = false;
$staffLoadError = false;

try {
    $statement = $conn->prepare(
        "SELECT COUNT(*) AS total_staff,
                COALESCE(SUM(employment_status = 'Active'), 0) AS active_staff,
                COALESCE(SUM(employment_status = 'Inactive'), 0) AS inactive_staff,
                COALESCE(SUM(LOWER(position) LIKE '%groomer%'), 0) AS total_groomers
         FROM staff"
    );
    $statement->execute();
    $summary = $statement->get_result()->fetch_assoc();
    $statement->close();
} catch (mysqli_sql_exception $exception) {
    error_log('Pawsome admin staff summary query failed: ' . $exception->getMessage());
    $summaryLoadError = true;
}

try {
    $sql =
        'SELECT staff_id, first_name, last_name, position, contact_number, email,
                employment_status, profile_photo_path
         FROM staff';
    $parameters = [];
    $parameterTypes = '';
    if ($searchTerm !== '') {
        $sql .= " WHERE CONCAT_WS(' ', first_name, last_name) LIKE ?
                  OR position LIKE ?
                  OR contact_number LIKE ?
                  OR email LIKE ?";
        $pattern = '%' . $searchTerm . '%';
        $parameters = [$pattern, $pattern, $pattern, $pattern];
        $parameterTypes = 'ssss';
    }
    $sql .= ' ORDER BY (employment_status = \'Active\') DESC, last_name, first_name, staff_id';
    $statement = $conn->prepare($sql);
    if ($parameters) {
        $statement->bind_param($parameterTypes, ...$parameters);
    }
    $statement->execute();
    $staffMembers = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
} catch (mysqli_sql_exception $exception) {
    error_log('Pawsome admin staff directory query failed: ' . $exception->getMessage());
    $staffLoadError = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Manage Pawsome groomers and staff profiles.">
    <meta name="theme-color" content="#fbf8ff">
    <title>Groomers / Staff | Pawsome Admin</title>
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

            <main class="customer-main admin-main admin-staff-main" id="admin-main">
                <section class="customer-page-heading admin-page-heading">
                    <div>
                        <span class="eyebrow">Admin area</span>
                        <h1>Groomers <span>/ Staff</span></h1>
                        <p>Manage staff profiles and employment status.</p>
                    </div>
                    <button class="button button-primary admin-staff-add" id="add-staff-button" type="button">+ Add Staff</button>
                </section>

                <section class="admin-overview-grid admin-staff-summary-grid" aria-label="Staff summary">
                    <?php
                    $summaryCards = [
                        ['Total Staff', 'total_staff', 'All staff profiles'],
                        ['Active Staff', 'active_staff', 'Currently active'],
                        ['Inactive Staff', 'inactive_staff', 'Not currently active'],
                        ['Total Groomers', 'total_groomers', 'Staff in groomer positions'],
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
                    <p class="admin-alert" role="alert">We couldn't load the staff summary. Please try again later.</p>
                <?php endif; ?>

                <section class="admin-panel admin-staff-panel" aria-labelledby="staff-directory-title">
                    <div class="admin-panel-heading admin-staff-panel-heading">
                        <div>
                            <span class="eyebrow">Team directory</span>
                            <h2 id="staff-directory-title">Staff</h2>
                        </div>
                        <form class="admin-staff-search" role="search" method="get" action="staff.php">
                            <label for="staff-search">Search staff</label>
                            <input id="staff-search" name="search" type="search" maxlength="100" value="<?= auth_escape($searchTerm) ?>" placeholder="Name, position, phone, or email">
                            <button class="admin-staff-search-button" type="submit">Search</button>
                        </form>
                    </div>
                    <?php if ($staffLoadError): ?>
                        <p class="admin-panel-message" role="alert">We couldn't load staff right now. Please try again later.</p>
                    <?php elseif (!$staffMembers): ?>
                        <p class="admin-panel-message"><?= $searchTerm === '' ? 'No staff profiles have been added yet.' : 'No staff match your search.' ?></p>
                    <?php else: ?>
                        <div class="admin-table-scroll">
                            <table class="admin-table admin-staff-table">
                                <thead>
                                    <tr>
                                        <th scope="col">Staff Member</th>
                                        <th scope="col">Position</th>
                                        <th scope="col">Contact</th>
                                        <th scope="col">Email</th>
                                        <th scope="col">Status</th>
                                        <th scope="col">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($staffMembers as $staff): ?>
                                        <tr>
                                            <td>
                                                <div class="admin-staff-table-person">
                                                    <?php if (!empty($staff['profile_photo_path'])): ?>
                                                        <img class="admin-staff-table-photo" src="staff-photo.php?id=<?= (int) $staff['staff_id'] ?>" alt="">
                                                    <?php else: ?>
                                                        <span class="admin-staff-table-photo admin-staff-photo-placeholder" aria-hidden="true"><?= auth_escape(strtoupper(substr((string) $staff['first_name'], 0, 1))) ?></span>
                                                    <?php endif; ?>
                                                    <strong><?= auth_escape(trim((string) $staff['first_name'] . ' ' . (string) $staff['last_name'])) ?></strong>
                                                </div>
                                            </td>
                                            <td><?= auth_escape((string) $staff['position']) ?></td>
                                            <td><?= auth_escape((string) ($staff['contact_number'] ?: 'Not provided')) ?></td>
                                            <td><?= auth_escape((string) $staff['email']) ?></td>
                                            <td>
                                                <span class="admin-staff-status <?= $staff['employment_status'] === 'Active' ? 'is-active' : 'is-inactive' ?>">
                                                    <?= auth_escape((string) $staff['employment_status']) ?>
                                                </span>
                                            </td>
                                            <td><a class="admin-staff-details-button" href="staff-details.php?id=<?= (int) $staff['staff_id'] ?>">View Details</a></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>
            </main>
        </div>
    </div>

    <dialog class="admin-staff-dialog" id="staff-dialog" aria-labelledby="staff-dialog-title">
        <form class="admin-staff-dialog-form" method="post" action="staff.php" enctype="multipart/form-data">
            <header class="admin-staff-dialog-header">
                <div>
                    <span class="eyebrow">Team directory</span>
                    <h2 id="staff-dialog-title">Add Staff</h2>
                </div>
                <button class="admin-staff-dialog-close" id="close-staff-dialog" type="button" aria-label="Close staff form">&times;</button>
            </header>
            <?php if ($formError !== ''): ?>
                <p class="admin-staff-form-error" role="alert"><?= auth_escape($formError) ?></p>
            <?php endif; ?>
            <input type="hidden" name="csrf_token" value="<?= auth_escape($csrfToken) ?>">
            <input type="hidden" name="action" value="create">
            <div class="admin-staff-form-instructions">Fields marked required must be filled out.</div>
            <?php require __DIR__ . '/../includes/admin_staff_form_fields.php'; ?>
            <footer class="admin-staff-dialog-actions">
                <button class="admin-staff-cancel" id="cancel-staff-dialog" type="button">Cancel</button>
                <button class="button button-primary" type="submit">Save Staff</button>
            </footer>
        </form>
    </dialog>

    <script>
    (() => {
        const dialog = document.getElementById('staff-dialog');
        document.getElementById('add-staff-button').addEventListener('click', () => dialog.showModal());
        document.getElementById('close-staff-dialog').addEventListener('click', () => dialog.close());
        document.getElementById('cancel-staff-dialog').addEventListener('click', () => dialog.close());
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) dialog.close();
        });
        <?php if ($openAddDialog): ?>
        dialog.showModal();
        <?php endif; ?>
    })();
    </script>
</body>
</html>
