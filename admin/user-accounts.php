<?php
require_once __DIR__ . '/../includes/auth.php';
auth_require_admin();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/activity_log.php';

$adminFirstName = (string) ($_SESSION['first_name'] ?? 'admin');
$activeAdminPage = 'user-accounts';
$csrfToken = auth_csrf_token();
$allowedRoles = ['admin', 'customer'];
$allowedStatuses = ['Active', 'Inactive'];
$accountFormValues = [
    'first_name' => '',
    'last_name' => '',
    'email' => '',
    'username' => '',
    'phone' => '',
    'role' => 'customer',
];
$formMode = 'create';
$editingAccountId = 0;
$openAccountDialog = false;
$openResetDialog = false;
$resetAccountId = 0;
$pageError = '';
$notice = auth_take_flash('account_notice');

$postString = static function (string $key): string {
    return isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : '';
};
$lockActiveAdmins = static function () use ($conn): array {
    $result = $conn->query(
        "SELECT user_id
         FROM users
         WHERE role = 'admin' AND account_status = 'Active'
         FOR UPDATE"
    );
    $adminIds = [];
    while ($row = $result->fetch_assoc()) {
        $adminIds[] = (int) $row['user_id'];
    }
    return $adminIds;
};
$lockAccount = static function (int $accountId) use ($conn): ?array {
    $statement = $conn->prepare(
        'SELECT user_id, first_name, last_name, email, username, phone, role, account_status
         FROM users
         WHERE user_id = ?
         FOR UPDATE'
    );
    $statement->bind_param('i', $accountId);
    $statement->execute();
    $account = $statement->get_result()->fetch_assoc();
    $statement->close();
    return $account ?: null;
};
$redirectWithNotice = static function (string $message): void {
    auth_set_flash('account_notice', $message);
    header('Location: user-accounts.php', true, 303);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!auth_csrf_is_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        exit('Your session expired. Reload the page and try again.');
    }

    $action = $postString('action');
    $actorId = (int) $_SESSION['user_id'];

    if ($action === 'create' || $action === 'update') {
        $formMode = $action;
        $openAccountDialog = true;
        $editingAccountId = filter_var($postString('account_id'), FILTER_VALIDATE_INT) ?: 0;
        foreach ($accountFormValues as $key => $defaultValue) {
            $accountFormValues[$key] = $postString($key);
        }
        if (!in_array($accountFormValues['role'], $allowedRoles, true)) {
            $accountFormValues['role'] = 'customer';
        }
        $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
        $passwordConfirmation = isset($_POST['password_confirmation']) && is_string($_POST['password_confirmation'])
            ? $_POST['password_confirmation']
            : '';

        $email = $accountFormValues['email'];
        $validAccountFields = $accountFormValues['first_name'] !== ''
            && auth_string_length($accountFormValues['first_name']) <= 100
            && $accountFormValues['last_name'] !== ''
            && auth_string_length($accountFormValues['last_name']) <= 100
            && $email !== ''
            && auth_string_length($email) <= 150
            && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            && $accountFormValues['username'] !== ''
            && auth_string_length($accountFormValues['username']) <= 50
            && auth_string_length($accountFormValues['phone']) <= 20
            && in_array($accountFormValues['role'], $allowedRoles, true);

        if (!$validAccountFields) {
            $pageError = 'Check the required fields, role, and allowed field lengths.';
        } elseif ($action === 'create' && (auth_string_length($password) < 12 || strlen($password) > 72)) {
            $pageError = 'Choose an initial password of at least 12 characters and no more than 72 bytes.';
        } elseif ($action === 'create' && $password !== $passwordConfirmation) {
            $pageError = 'The password and confirmation do not match.';
        } elseif ($action === 'update' && $editingAccountId < 1) {
            $pageError = 'Choose a valid account to edit.';
        } elseif ($action === 'update' && $editingAccountId === $actorId && $accountFormValues['role'] !== 'admin') {
            $pageError = 'You cannot change your own administrator role.';
        } else {
            $transactionStarted = false;
            try {
                if ($action === 'create') {
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    $phone = $accountFormValues['phone'] === '' ? null : $accountFormValues['phone'];
                    $conn->begin_transaction();
                    $transactionStarted = true;
                    $statement = $conn->prepare(
                        'INSERT INTO users (first_name, last_name, email, username, password, phone, role)
                         VALUES (?, ?, ?, ?, ?, ?, ?)'
                    );
                    $statement->bind_param(
                        'sssssss',
                        $accountFormValues['first_name'],
                        $accountFormValues['last_name'],
                        $email,
                        $accountFormValues['username'],
                        $passwordHash,
                        $phone,
                        $accountFormValues['role']
                    );
                    $statement->execute();
                    $targetId = (int) $conn->insert_id;
                    $statement->close();
                    activity_log_current_user(
                        $conn,
                        'account.created',
                        'Created account for ' . trim($accountFormValues['first_name'] . ' ' . $accountFormValues['last_name']) . '.',
                        [
                            'account_id' => $targetId,
                            'account_name' => trim($accountFormValues['first_name'] . ' ' . $accountFormValues['last_name']),
                            'role' => $accountFormValues['role'],
                        ]
                    );
                    $conn->commit();
                    $transactionStarted = false;
                    $redirectWithNotice('The account was created.');
                }

                try {
                    $conn->begin_transaction();
                    $transactionStarted = true;
                    $activeAdminIds = $lockActiveAdmins();
                    $target = $lockAccount($editingAccountId);

                    if ($target === null) {
                        $pageError = 'That account could not be found.';
                    } elseif (
                        $target['role'] === 'admin'
                        && $target['account_status'] === 'Active'
                        && $accountFormValues['role'] !== 'admin'
                        && count($activeAdminIds) <= 1
                    ) {
                        $pageError = 'The last active admin cannot be demoted.';
                    } else {
                        $phone = $accountFormValues['phone'] === '' ? null : $accountFormValues['phone'];
                        $roleChanged = $target['role'] !== $accountFormValues['role'];
                        $updateSql = $roleChanged
                            ? 'UPDATE users
                               SET first_name = ?, last_name = ?, email = ?, username = ?, phone = ?,
                                   role = ?, auth_version = auth_version + 1
                               WHERE user_id = ?'
                            : 'UPDATE users
                               SET first_name = ?, last_name = ?, email = ?, username = ?, phone = ?, role = ?
                               WHERE user_id = ?';
                        $statement = $conn->prepare($updateSql);
                        $statement->bind_param(
                            'ssssssi',
                            $accountFormValues['first_name'],
                            $accountFormValues['last_name'],
                            $email,
                            $accountFormValues['username'],
                            $phone,
                            $accountFormValues['role'],
                            $editingAccountId
                        );
                        $statement->execute();
                        $statement->close();

                        $changedFields = [];
                        foreach (['first_name', 'last_name', 'email', 'username'] as $field) {
                            if ((string) $target[$field] !== (string) $accountFormValues[$field]) {
                                $changedFields[] = $field;
                            }
                        }
                        if ((string) ($target['phone'] ?? '') !== (string) ($phone ?? '')) {
                            $changedFields[] = 'phone';
                        }
                        if ($roleChanged) {
                            $changedFields[] = 'role';
                        }
                        if ($changedFields !== []) {
                            $targetName = trim((string) $accountFormValues['first_name'] . ' ' . (string) $accountFormValues['last_name']);
                            $actionType = $roleChanged ? 'account.role_changed' : 'account.updated';
                            $description = $roleChanged
                                ? 'Changed the role for account #' . $editingAccountId . ' from ' . $target['role'] . ' to ' . $accountFormValues['role'] . '.'
                                : 'Updated account details for ' . $targetName . '.';
                            $activityDetails = [
                                'account_id' => (int) $editingAccountId,
                                'account_name' => $targetName,
                                'changed_fields' => $changedFields,
                            ];
                            if ($roleChanged) {
                                $activityDetails['from_role'] = (string) $target['role'];
                                $activityDetails['to_role'] = $accountFormValues['role'];
                            }
                            activity_log_current_user($conn, $actionType, $description, $activityDetails);
                        }
                    }

                    if ($pageError !== '') {
                        $conn->rollback();
                        $transactionStarted = false;
                    } else {
                        $conn->commit();
                        $transactionStarted = false;
                        $redirectWithNotice('The account was updated.');
                    }
                } catch (mysqli_sql_exception $exception) {
                    if ($transactionStarted) {
                        $conn->rollback();
                        $transactionStarted = false;
                    }
                    throw $exception;
                }
            } catch (mysqli_sql_exception $exception) {
                if ($transactionStarted) {
                    $conn->rollback();
                }
                if ((int) $exception->getCode() === 1062) {
                    $pageError = 'An account already uses that email address or username.';
                } else {
                    error_log('Pawsome admin account save failed: ' . $exception->getMessage());
                    $pageError = 'We could not save this account right now. Please try again.';
                }
            }
        }
    } elseif ($action === 'set_status') {
        $targetId = filter_var($postString('account_id'), FILTER_VALIDATE_INT) ?: 0;
        $targetStatus = $postString('account_status');
        if ($targetId < 1 || !in_array($targetStatus, $allowedStatuses, true)) {
            $pageError = 'Choose a valid account and status.';
        } else {
            $transactionStarted = false;
            try {
                $conn->begin_transaction();
                $transactionStarted = true;
                $activeAdminIds = $lockActiveAdmins();
                $target = $lockAccount($targetId);

                if ($target === null) {
                    $pageError = 'That account could not be found.';
                } elseif ($targetId === $actorId && $targetStatus === 'Inactive') {
                    $pageError = 'You cannot deactivate your own account.';
                } elseif (
                    $target['role'] === 'admin'
                    && $target['account_status'] === 'Active'
                    && $targetStatus === 'Inactive'
                    && count($activeAdminIds) <= 1
                ) {
                    $pageError = 'The last active admin cannot be deactivated.';
                } else {
                    $statement = $conn->prepare(
                        'UPDATE users
                         SET account_status = ?, auth_version = auth_version + 1
                         WHERE user_id = ?'
                    );
                    $statement->bind_param('si', $targetStatus, $targetId);
                    $statement->execute();
                    $statement->close();
                    if ($target['account_status'] !== $targetStatus) {
                        $targetName = trim((string) $target['first_name'] . ' ' . (string) $target['last_name']);
                        activity_log_current_user(
                            $conn,
                            'account.status_changed',
                            'Changed account status for ' . $targetName . ' from ' . $target['account_status'] . ' to ' . $targetStatus . '.',
                            [
                                'account_id' => (int) $targetId,
                                'account_name' => $targetName,
                                'role' => (string) $target['role'],
                                'from_status' => (string) $target['account_status'],
                                'to_status' => $targetStatus,
                            ]
                        );
                    }
                }

                if ($pageError !== '') {
                    $conn->rollback();
                    $transactionStarted = false;
                } else {
                    $conn->commit();
                    $transactionStarted = false;
                    $redirectWithNotice(
                        $targetStatus === 'Active' ? 'The account was activated.' : 'The account was deactivated.'
                    );
                }
            } catch (mysqli_sql_exception $exception) {
                if ($transactionStarted) {
                    $conn->rollback();
                }
                error_log('Pawsome admin account status update failed: ' . $exception->getMessage());
                $pageError = 'We could not update the account status right now. Please try again.';
            }
        }
    } elseif ($action === 'reset_password') {
        $resetAccountId = filter_var($postString('account_id'), FILTER_VALIDATE_INT) ?: 0;
        $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
        $passwordConfirmation = isset($_POST['password_confirmation']) && is_string($_POST['password_confirmation'])
            ? $_POST['password_confirmation']
            : '';
        $openResetDialog = true;

        if ($resetAccountId < 1) {
            $pageError = 'Choose a valid account.';
        } elseif (auth_string_length($password) < 12 || strlen($password) > 72) {
            $pageError = 'Choose a password of at least 12 characters and no more than 72 bytes.';
        } elseif ($password !== $passwordConfirmation) {
            $pageError = 'The password and confirmation do not match.';
        } else {
            $transactionStarted = false;
            try {
                $conn->begin_transaction();
                $transactionStarted = true;
                $target = $lockAccount($resetAccountId);
                if ($target === null) {
                    $conn->rollback();
                    $transactionStarted = false;
                    $pageError = 'That account could not be found.';
                } else {
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    $statement = $conn->prepare(
                        'UPDATE users
                         SET password = ?, auth_version = auth_version + 1
                         WHERE user_id = ?'
                    );
                    $statement->bind_param('si', $passwordHash, $resetAccountId);
                    $statement->execute();
                    $updated = $statement->affected_rows;
                    $statement->close();
                    if ($updated < 1) {
                        $conn->rollback();
                        $transactionStarted = false;
                        $pageError = 'That account could not be found or the password was unchanged.';
                    } else {
                        $targetName = trim((string) $target['first_name'] . ' ' . (string) $target['last_name']);
                        activity_log_current_user(
                            $conn,
                            'account.password_reset',
                            'Reset the password for ' . $targetName . '.',
                            [
                                'account_id' => (int) $resetAccountId,
                                'account_name' => $targetName,
                                'role' => (string) $target['role'],
                            ]
                        );
                        $conn->commit();
                        $transactionStarted = false;
                        $redirectWithNotice('The password was reset. Existing sessions for that account must sign in again.');
                    }
                }
            } catch (mysqli_sql_exception $exception) {
                if ($transactionStarted) {
                    $conn->rollback();
                }
                error_log('Pawsome admin account password reset failed: ' . $exception->getMessage());
                $pageError = 'We could not reset the password right now. Please try again.';
            }
        }
    } else {
        http_response_code(400);
        exit('Choose a valid account action.');
    }
}

$searchTerm = $_GET['search'] ?? '';
$roleFilter = $_GET['role'] ?? '';
$statusFilter = $_GET['status'] ?? '';
if (
    !is_string($searchTerm)
    || auth_string_length(trim($searchTerm)) > 100
    || !is_string($roleFilter)
    || ($roleFilter !== '' && !in_array($roleFilter, $allowedRoles, true))
    || !is_string($statusFilter)
    || ($statusFilter !== '' && !in_array($statusFilter, $allowedStatuses, true))
) {
    http_response_code(400);
    exit('Enter valid account search and filter values.');
}
$searchTerm = trim($searchTerm);

$summary = null;
$accounts = [];
$summaryLoadError = false;
$accountsLoadError = false;
$activeAdminCount = 0;
try {
    $statement = $conn->prepare(
        "SELECT COUNT(*) AS total_accounts,
                COALESCE(SUM(role = 'admin'), 0) AS admin_accounts,
                COALESCE(SUM(role = 'customer'), 0) AS customer_accounts,
                COALESCE(SUM(account_status = 'Active'), 0) AS active_accounts,
                COALESCE(SUM(role = 'admin' AND account_status = 'Active'), 0) AS active_admin_accounts
         FROM users"
    );
    $statement->execute();
    $summary = $statement->get_result()->fetch_assoc();
    $statement->close();
    $activeAdminCount = (int) ($summary['active_admin_accounts'] ?? 0);
} catch (mysqli_sql_exception $exception) {
    error_log('Pawsome admin account summary query failed: ' . $exception->getMessage());
    $summaryLoadError = true;
}

try {
    $sql = 'SELECT user_id, first_name, last_name, email, username, role, account_status, created_at FROM users WHERE 1 = 1';
    $parameters = [];
    $parameterTypes = '';
    if ($searchTerm !== '') {
        $sql .= " AND (CONCAT_WS(' ', first_name, last_name) LIKE ? OR email LIKE ? OR username LIKE ?)";
        $pattern = '%' . $searchTerm . '%';
        array_push($parameters, $pattern, $pattern, $pattern);
        $parameterTypes .= 'sss';
    }
    if ($roleFilter !== '') {
        $sql .= ' AND role = ?';
        $parameters[] = $roleFilter;
        $parameterTypes .= 's';
    }
    if ($statusFilter !== '') {
        $sql .= ' AND account_status = ?';
        $parameters[] = $statusFilter;
        $parameterTypes .= 's';
    }
    $sql .= " ORDER BY (account_status = 'Active') DESC, created_at DESC, user_id DESC";
    $statement = $conn->prepare($sql);
    if ($parameters) {
        $statement->bind_param($parameterTypes, ...$parameters);
    }
    $statement->execute();
    $accounts = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
} catch (mysqli_sql_exception $exception) {
    error_log('Pawsome admin accounts query failed: ' . $exception->getMessage());
    $accountsLoadError = true;
}

$accountFormQueryId = $editingAccountId;
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $requestedEditId = $_GET['edit'] ?? '';
    if (is_string($requestedEditId) && $requestedEditId !== '') {
        $accountFormQueryId = filter_var($requestedEditId, FILTER_VALIDATE_INT) ?: 0;
        if ($accountFormQueryId > 0) {
            $formMode = 'update';
            $openAccountDialog = true;
        } else {
            $pageError = 'Choose a valid account to edit.';
        }
    }
}

$editingAccount = null;
if ($accountFormQueryId > 0) {
    try {
        $statement = $conn->prepare(
            'SELECT user_id, first_name, last_name, email, username, phone, role, account_status
             FROM users
             WHERE user_id = ?
             LIMIT 1'
        );
        $statement->bind_param('i', $accountFormQueryId);
        $statement->execute();
        $editingAccount = $statement->get_result()->fetch_assoc();
        $statement->close();
        if ($editingAccount && $_SERVER['REQUEST_METHOD'] !== 'POST') {
            foreach ($accountFormValues as $key => $defaultValue) {
                $accountFormValues[$key] = (string) ($editingAccount[$key] ?? '');
            }
        } elseif (!$editingAccount) {
            $pageError = 'That account could not be found.';
            $openAccountDialog = false;
        }
    } catch (mysqli_sql_exception $exception) {
        error_log('Pawsome admin account edit lookup failed: ' . $exception->getMessage());
        $pageError = 'We could not load that account right now. Please try again.';
        $openAccountDialog = false;
    }
}

$viewAccount = null;
$viewAccountId = $_GET['view'] ?? '';
if (is_string($viewAccountId) && $viewAccountId !== '') {
    $viewAccountId = filter_var($viewAccountId, FILTER_VALIDATE_INT) ?: 0;
    if ($viewAccountId > 0) {
        try {
            $statement = $conn->prepare(
                'SELECT user_id, first_name, last_name, email, username, phone, role, account_status, created_at
                 FROM users
                 WHERE user_id = ?
                 LIMIT 1'
            );
            $statement->bind_param('i', $viewAccountId);
            $statement->execute();
            $viewAccount = $statement->get_result()->fetch_assoc();
            $statement->close();
            if (!$viewAccount) {
                $pageError = 'That account could not be found.';
            }
        } catch (mysqli_sql_exception $exception) {
            error_log('Pawsome admin account details lookup failed: ' . $exception->getMessage());
            $pageError = 'We could not load that account right now. Please try again.';
        }
    } else {
        $pageError = 'Choose a valid account to view.';
    }
}

$resetAccountName = '';
if ($resetAccountId > 0) {
    foreach ($accounts as $account) {
        if ((int) $account['user_id'] === $resetAccountId) {
            $resetAccountName = trim((string) $account['first_name'] . ' ' . (string) $account['last_name']);
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Manage Pawsome administrator and customer accounts.">
    <meta name="theme-color" content="#fbf8ff">
    <title>User Accounts | Pawsome Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800;900&family=Nunito:wght@500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/shared/portal.css">
    <link rel="stylesheet" href="../assets/css/admin/dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin/user-accounts.css">
</head>
<body class="customer-app admin-app admin-user-accounts-page">
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

            <main class="customer-main admin-main admin-user-accounts-main" id="admin-main">
                <section class="customer-page-heading admin-page-heading admin-user-accounts-heading">
                    <div>
                        <span class="eyebrow">Admin area</span>
                        <h1>User <span>Accounts</span></h1>
                        <p>Manage administrator and customer access. Staff profiles do not have login accounts.</p>
                    </div>
                    <button class="button button-primary admin-account-add" id="add-account-button" type="button">+ Add Account</button>
                </section>

                <?php if ($notice !== null): ?>
                    <p class="admin-account-notice" role="status"><?= auth_escape($notice) ?></p>
                <?php endif; ?>
                <?php if ($pageError !== ''): ?>
                    <p class="admin-alert" role="alert"><?= auth_escape($pageError) ?></p>
                <?php endif; ?>

                <section class="admin-overview-grid admin-account-summary-grid" aria-label="Account summary">
                    <?php
                    $summaryCards = [
                        ['Total Accounts', 'total_accounts', 'All registered user accounts'],
                        ['Admin Accounts', 'admin_accounts', 'Accounts with admin access'],
                        ['Customer Accounts', 'customer_accounts', 'Accounts for pet owners'],
                        ['Active Accounts', 'active_accounts', 'Currently allowed to sign in'],
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
                    <p class="admin-alert" role="alert">We couldn't load the account summary. Please try again later.</p>
                <?php endif; ?>

                <section class="admin-panel admin-account-panel" aria-labelledby="accounts-title">
                    <div class="admin-panel-heading admin-account-panel-heading">
                        <div>
                            <span class="eyebrow">Account directory</span>
                            <h2 id="accounts-title">Accounts</h2>
                        </div>
                        <form class="admin-account-filters" method="get" action="user-accounts.php" role="search">
                            <label class="admin-account-search">
                                <span>Search accounts</span>
                                <input type="search" name="search" maxlength="100" value="<?= auth_escape($searchTerm) ?>" placeholder="Name, email, or username">
                            </label>
                            <label>
                                <span>Role</span>
                                <select name="role">
                                    <option value="">All roles</option>
                                    <?php foreach ($allowedRoles as $role): ?>
                                        <option value="<?= auth_escape($role) ?>"<?= $roleFilter === $role ? ' selected' : '' ?>><?= auth_escape(ucfirst($role)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>
                                <span>Status</span>
                                <select name="status">
                                    <option value="">All statuses</option>
                                    <?php foreach ($allowedStatuses as $status): ?>
                                        <option value="<?= auth_escape($status) ?>"<?= $statusFilter === $status ? ' selected' : '' ?>><?= auth_escape($status) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        </form>
                    </div>

                    <?php if ($accountsLoadError): ?>
                        <p class="admin-panel-message" role="alert">We couldn't load accounts right now. Please try again later.</p>
                    <?php elseif (!$accounts): ?>
                        <p class="admin-panel-message"><?= $searchTerm === '' && $roleFilter === '' && $statusFilter === '' ? 'No accounts are available.' : 'No accounts match your search and filters.' ?></p>
                    <?php else: ?>
                        <div class="admin-table-scroll">
                            <table class="admin-table admin-user-accounts-table">
                                <thead>
                                    <tr>
                                        <th scope="col">User ID</th>
                                        <th scope="col">Full Name</th>
                                        <th scope="col">Email</th>
                                        <th scope="col">Username</th>
                                        <th scope="col">Role</th>
                                        <th scope="col">Date Created</th>
                                        <th scope="col">Account Status</th>
                                        <th scope="col">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($accounts as $account): ?>
                                        <?php
                                        $accountId = (int) $account['user_id'];
                                        $isCurrentAccount = $accountId === (int) $_SESSION['user_id'];
                                        $isProtectedAdmin = $account['role'] === 'admin'
                                            && $account['account_status'] === 'Active'
                                            && $activeAdminCount <= 1;
                                        $cannotDeactivate = ($isCurrentAccount && $account['account_status'] === 'Active')
                                            || $isProtectedAdmin;
                                        $cannotDeactivateTitle = $isCurrentAccount && $account['account_status'] === 'Active'
                                            ? 'You cannot deactivate your own account.'
                                            : 'The last active admin cannot be deactivated.';
                                        ?>
                                        <tr>
                                            <td class="admin-reference">#<?= $accountId ?></td>
                                            <td><strong><?= auth_escape(trim((string) $account['first_name'] . ' ' . (string) $account['last_name'])) ?></strong></td>
                                            <td><?= auth_escape((string) $account['email']) ?></td>
                                            <td><?= auth_escape((string) $account['username']) ?></td>
                                            <td><span class="admin-account-role"><?= auth_escape(ucfirst((string) $account['role'])) ?></span></td>
                                            <td><?= auth_escape(date('M j, Y', strtotime((string) $account['created_at']))) ?></td>
                                            <td>
                                                <span class="admin-account-status<?= $account['account_status'] === 'Active' ? ' is-active' : ' is-inactive' ?>">
                                                    <?= auth_escape((string) $account['account_status']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="admin-account-actions">
                                                    <button
                                                        class="admin-account-menu-trigger"
                                                        type="button"
                                                        id="account-actions-trigger-<?= $accountId ?>"
                                                        aria-label="Open actions for account #<?= $accountId ?>"
                                                        aria-haspopup="menu"
                                                        aria-expanded="false"
                                                        aria-controls="account-action-menu"
                                                        data-account-menu-trigger
                                                        data-account-id="<?= $accountId ?>"
                                                        data-account-name="<?= auth_escape(trim((string) $account['first_name'] . ' ' . (string) $account['last_name'])) ?>"
                                                        data-account-status="<?= auth_escape((string) $account['account_status']) ?>"
                                                        data-can-change-status="<?= $cannotDeactivate ? '0' : '1' ?>"
                                                        data-status-title="<?= auth_escape($cannotDeactivateTitle) ?>"
                                                    ><span aria-hidden="true">⋮</span></button>
                                                </div>
                                            </td>
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

    <div class="admin-account-menu" id="account-action-menu" role="menu" aria-label="Account actions" hidden>
        <a class="admin-account-menu-item" id="account-menu-edit" href="#" role="menuitem" tabindex="-1">Edit Details</a>
        <button class="admin-account-menu-item" id="account-menu-reset" type="button" role="menuitem" tabindex="-1">Reset Password</button>
        <button class="admin-account-menu-item" id="account-menu-status" type="button" role="menuitem" tabindex="-1">Deactivate Account</button>
    </div>

    <dialog class="admin-user-account-dialog" id="account-dialog" aria-labelledby="account-dialog-title">
        <form class="admin-account-dialog-form" method="post" action="user-accounts.php">
            <header class="admin-account-dialog-header">
                <div>
                    <span class="eyebrow">Account directory</span>
                    <h2 id="account-dialog-title"><?= $formMode === 'update' ? 'Edit Account' : 'Add Account' ?></h2>
                </div>
                <button class="admin-account-dialog-close" type="button" data-close-dialog aria-label="Close account form">&times;</button>
            </header>
            <?php if ($pageError !== '' && $openAccountDialog): ?>
                <p class="admin-account-form-error" role="alert"><?= auth_escape($pageError) ?></p>
            <?php endif; ?>
            <input type="hidden" name="csrf_token" value="<?= auth_escape($csrfToken) ?>">
            <input type="hidden" name="action" value="<?= $formMode === 'update' ? 'update' : 'create' ?>">
            <?php if ($formMode === 'update'): ?>
                <input type="hidden" name="account_id" value="<?= (int) $editingAccountId ?>">
            <?php endif; ?>
            <div class="admin-account-form-grid">
                <label>
                    <span>First name</span>
                    <input type="text" name="first_name" maxlength="100" autocomplete="given-name" value="<?= auth_escape($accountFormValues['first_name']) ?>" required>
                </label>
                <label>
                    <span>Last name</span>
                    <input type="text" name="last_name" maxlength="100" autocomplete="family-name" value="<?= auth_escape($accountFormValues['last_name']) ?>" required>
                </label>
                <label>
                    <span>Email</span>
                    <input type="email" name="email" maxlength="150" autocomplete="email" value="<?= auth_escape($accountFormValues['email']) ?>" required>
                </label>
                <label>
                    <span>Username</span>
                    <input type="text" name="username" maxlength="50" autocomplete="username" value="<?= auth_escape($accountFormValues['username']) ?>" required>
                </label>
                <label>
                    <span>Phone <small>(optional)</small></span>
                    <input type="tel" name="phone" maxlength="20" autocomplete="tel" value="<?= auth_escape($accountFormValues['phone']) ?>">
                </label>
                <label>
                    <span>Role</span>
                    <?php if ($formMode === 'update' && $editingAccount && (int) $editingAccount['user_id'] === (int) $_SESSION['user_id']): ?>
                        <input type="hidden" name="role" value="admin">
                        <input type="text" value="Admin" aria-label="Role" readonly>
                        <small class="admin-account-field-note">You cannot change your own administrator role.</small>
                    <?php else: ?>
                        <select name="role" required>
                            <?php foreach ($allowedRoles as $role): ?>
                                <option value="<?= auth_escape($role) ?>"<?= $accountFormValues['role'] === $role ? ' selected' : '' ?>><?= auth_escape(ucfirst($role)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </label>
                <?php if ($formMode === 'create'): ?>
                    <label>
                        <span>Initial password</span>
                        <input type="password" name="password" minlength="12" maxlength="72" autocomplete="new-password" required>
                    </label>
                    <label>
                        <span>Confirm password</span>
                        <input type="password" name="password_confirmation" minlength="12" maxlength="72" autocomplete="new-password" required>
                    </label>
                    <p class="admin-account-form-hint">Use at least 12 characters. Passwords are hashed before storage and are never displayed.</p>
                <?php endif; ?>
            </div>
            <footer class="admin-account-dialog-actions">
                <button class="admin-account-cancel" type="button" data-close-dialog>Cancel</button>
                <button class="button button-primary" type="submit"><?= $formMode === 'update' ? 'Save Changes' : 'Create Account' ?></button>
            </footer>
        </form>
    </dialog>

    <dialog class="admin-user-account-dialog admin-account-details-dialog" id="details-dialog" aria-labelledby="details-dialog-title">
        <?php if ($viewAccount): ?>
            <div class="admin-account-dialog-form">
                <header class="admin-account-dialog-header">
                    <div>
                        <span class="eyebrow">Account #<?= (int) $viewAccount['user_id'] ?></span>
                        <h2 id="details-dialog-title">Account Details</h2>
                    </div>
                    <button class="admin-account-dialog-close" type="button" data-close-dialog aria-label="Close account details">&times;</button>
                </header>
                <dl class="admin-account-details-list">
                    <div><dt>Full name</dt><dd><?= auth_escape(trim((string) $viewAccount['first_name'] . ' ' . (string) $viewAccount['last_name'])) ?></dd></div>
                    <div><dt>Email</dt><dd><?= auth_escape((string) $viewAccount['email']) ?></dd></div>
                    <div><dt>Username</dt><dd><?= auth_escape((string) $viewAccount['username']) ?></dd></div>
                    <div><dt>Phone</dt><dd><?= auth_escape(trim((string) ($viewAccount['phone'] ?? '')) ?: 'Not provided') ?></dd></div>
                    <div><dt>Role</dt><dd><?= auth_escape(ucfirst((string) $viewAccount['role'])) ?></dd></div>
                    <div><dt>Account status</dt><dd><?= auth_escape((string) $viewAccount['account_status']) ?></dd></div>
                    <div><dt>Date created</dt><dd><?= auth_escape(date('M j, Y g:i A', strtotime((string) $viewAccount['created_at']))) ?></dd></div>
                </dl>
                <footer class="admin-account-dialog-actions">
                    <a class="admin-account-cancel" href="user-accounts.php?edit=<?= (int) $viewAccount['user_id'] ?>">Edit Account</a>
                    <button class="button button-primary" type="button" data-close-dialog>Done</button>
                </footer>
            </div>
        <?php endif; ?>
    </dialog>

    <dialog class="admin-user-account-dialog admin-account-reset-dialog" id="reset-dialog" aria-labelledby="reset-dialog-title">
        <form class="admin-account-dialog-form" method="post" action="user-accounts.php">
            <header class="admin-account-dialog-header">
                <div>
                    <span class="eyebrow">Secure account access</span>
                    <h2 id="reset-dialog-title">Reset Password</h2>
                    <p id="reset-account-name"></p>
                </div>
                <button class="admin-account-dialog-close" type="button" data-close-dialog aria-label="Close password reset">&times;</button>
            </header>
            <?php if ($pageError !== '' && $openResetDialog): ?>
                <p class="admin-account-form-error" role="alert"><?= auth_escape($pageError) ?></p>
            <?php endif; ?>
            <p class="admin-account-form-hint">Set a temporary password and share it with the account holder through a secure channel. Their existing sessions will be revoked and they must sign in again.</p>
            <input type="hidden" name="csrf_token" value="<?= auth_escape($csrfToken) ?>">
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="account_id" id="reset-account-id" value="<?= (int) $resetAccountId ?>">
            <div class="admin-account-form-grid">
                <label>
                    <span>New temporary password</span>
                    <input type="password" name="password" minlength="12" maxlength="72" autocomplete="new-password" required>
                </label>
                <label>
                    <span>Confirm temporary password</span>
                    <input type="password" name="password_confirmation" minlength="12" maxlength="72" autocomplete="new-password" required>
                </label>
            </div>
            <footer class="admin-account-dialog-actions">
                <button class="admin-account-cancel" type="button" data-close-dialog>Cancel</button>
                <button class="button button-primary" type="submit">Reset Password</button>
            </footer>
        </form>
    </dialog>

    <dialog class="admin-user-account-dialog admin-account-confirm-dialog" id="status-confirm-dialog" aria-labelledby="status-confirm-title">
        <form class="admin-account-dialog-form" method="post" action="user-accounts.php">
            <header class="admin-account-dialog-header">
                <div>
                    <span class="eyebrow">Account access</span>
                    <h2 id="status-confirm-title">Deactivate Account</h2>
                </div>
                <button class="admin-account-dialog-close" type="button" data-close-dialog aria-label="Close confirmation">&times;</button>
            </header>
            <p class="admin-account-form-hint" id="status-confirm-message"></p>
            <input type="hidden" name="csrf_token" value="<?= auth_escape($csrfToken) ?>">
            <input type="hidden" name="action" value="set_status">
            <input type="hidden" name="account_id" id="status-account-id" value="">
            <input type="hidden" name="account_status" id="status-account-value" value="">
            <footer class="admin-account-dialog-actions">
                <button class="admin-account-cancel" type="button" data-close-dialog>Cancel</button>
                <button class="button button-primary admin-account-confirm-submit" id="status-confirm-submit" type="submit">Deactivate Account</button>
            </footer>
        </form>
    </dialog>

    <script>
    (() => {
        const accountDialog = document.getElementById('account-dialog');
        const detailsDialog = document.getElementById('details-dialog');
        const resetDialog = document.getElementById('reset-dialog');
        const statusDialog = document.getElementById('status-confirm-dialog');
        const menu = document.getElementById('account-action-menu');
        const menuEdit = document.getElementById('account-menu-edit');
        const menuReset = document.getElementById('account-menu-reset');
        const menuStatus = document.getElementById('account-menu-status');
        const accountFilters = document.querySelector('.admin-account-filters');
        const accountSearch = accountFilters.querySelector('input[type="search"]');
        let accountFilterTimer = null;
        let activeMenuTrigger = null;

        const submitAccountFilters = () => {
            if (accountFilterTimer !== null) {
                window.clearTimeout(accountFilterTimer);
                accountFilterTimer = null;
            }
            accountFilters.requestSubmit();
        };

        accountSearch.addEventListener('input', () => {
            if (accountFilterTimer !== null) window.clearTimeout(accountFilterTimer);
            accountFilterTimer = window.setTimeout(submitAccountFilters, 300);
        });
        accountFilters.querySelectorAll('select').forEach((select) => {
            select.addEventListener('change', submitAccountFilters);
        });

        const closeAccountMenu = (restoreFocus) => {
            if (activeMenuTrigger) {
                activeMenuTrigger.setAttribute('aria-expanded', 'false');
            }
            menu.hidden = true;
            if (restoreFocus && activeMenuTrigger) {
                activeMenuTrigger.focus();
            }
            activeMenuTrigger = null;
        };

        const positionAccountMenu = (trigger) => {
            const triggerRect = trigger.getBoundingClientRect();
            menu.hidden = false;
            const menuRect = menu.getBoundingClientRect();
            const left = Math.max(8, Math.min(
                triggerRect.right - menuRect.width,
                window.innerWidth - menuRect.width - 8
            ));
            let top = triggerRect.bottom + 6;
            if (top + menuRect.height > window.innerHeight - 8) {
                top = Math.max(8, triggerRect.top - menuRect.height - 6);
            }
            menu.style.left = `${left}px`;
            menu.style.top = `${top}px`;
        };

        document.getElementById('add-account-button').addEventListener('click', () => accountDialog.showModal());
        document.querySelectorAll('[data-close-dialog]').forEach((button) => {
            button.addEventListener('click', () => button.closest('dialog').close());
        });
        document.querySelectorAll('[data-account-menu-trigger]').forEach((trigger) => {
            trigger.addEventListener('click', () => {
                if (activeMenuTrigger === trigger && !menu.hidden) {
                    closeAccountMenu(false);
                    return;
                }
                closeAccountMenu(false);
                activeMenuTrigger = trigger;
                trigger.setAttribute('aria-expanded', 'true');
                menuEdit.href = `user-accounts.php?edit=${encodeURIComponent(trigger.dataset.accountId)}`;
                menuReset.dataset.accountId = trigger.dataset.accountId;
                menuReset.dataset.accountName = trigger.dataset.accountName;
                const isActive = trigger.dataset.accountStatus === 'Active';
                menuStatus.textContent = isActive ? 'Deactivate Account' : 'Activate Account';
                menuStatus.dataset.accountId = trigger.dataset.accountId;
                menuStatus.dataset.accountName = trigger.dataset.accountName;
                menuStatus.dataset.accountStatus = isActive ? 'Inactive' : 'Active';
                menuStatus.classList.toggle('is-danger', isActive);
                menuStatus.classList.toggle('is-activate', !isActive);
                menuStatus.disabled = trigger.dataset.canChangeStatus !== '1';
                menuStatus.title = menuStatus.disabled ? trigger.dataset.statusTitle : '';
                positionAccountMenu(trigger);
                menuEdit.focus();
            });
        });
        menu.addEventListener('click', (event) => {
            if (event.target.closest('[role="menuitem"]') && !event.target.disabled) {
                closeAccountMenu(false);
            }
        });
        menuReset.addEventListener('click', () => {
            document.getElementById('reset-account-id').value = menuReset.dataset.accountId;
            document.getElementById('reset-account-name').textContent = menuReset.dataset.accountName;
            resetDialog.showModal();
        });
        menuStatus.addEventListener('click', () => {
            if (menuStatus.disabled) return;
            const targetStatus = menuStatus.dataset.accountStatus;
            const activating = targetStatus === 'Active';
            document.getElementById('status-account-id').value = menuStatus.dataset.accountId;
            document.getElementById('status-account-value').value = targetStatus;
            document.getElementById('status-confirm-title').textContent = activating ? 'Activate Account' : 'Deactivate Account';
            document.getElementById('status-confirm-message').textContent = activating
                ? `Activate ${menuStatus.dataset.accountName}'s account? They will be able to sign in again.`
                : `Deactivate ${menuStatus.dataset.accountName}'s account? They will no longer be able to sign in.`;
            const confirmButton = document.getElementById('status-confirm-submit');
            confirmButton.textContent = activating ? 'Activate Account' : 'Deactivate Account';
            confirmButton.classList.toggle('is-danger', !activating);
            statusDialog.showModal();
        });
        document.addEventListener('pointerdown', (event) => {
            if (
                !menu.hidden
                && !menu.contains(event.target)
                && !(activeMenuTrigger && activeMenuTrigger.contains(event.target))
            ) {
                closeAccountMenu(false);
            }
        });
        menu.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                closeAccountMenu(true);
                return;
            }
            const items = Array.from(menu.querySelectorAll('[role="menuitem"]:not(:disabled)'));
            const currentIndex = items.indexOf(document.activeElement);
            let nextIndex = currentIndex;
            if (event.key === 'ArrowDown') nextIndex = (currentIndex + 1) % items.length;
            if (event.key === 'ArrowUp') nextIndex = (currentIndex - 1 + items.length) % items.length;
            if (event.key === 'Home') nextIndex = 0;
            if (event.key === 'End') nextIndex = items.length - 1;
            if (nextIndex !== currentIndex) {
                event.preventDefault();
                items[nextIndex].focus();
            }
        });
        window.addEventListener('scroll', () => closeAccountMenu(false), true);
        window.addEventListener('resize', () => closeAccountMenu(false));
        [accountDialog, detailsDialog, resetDialog, statusDialog].forEach((dialog) => {
            dialog.addEventListener('click', (event) => {
                if (event.target === dialog) dialog.close();
            });
        });
        <?php if ($openAccountDialog): ?>
        accountDialog.showModal();
        <?php endif; ?>
        <?php if ($viewAccount): ?>
        detailsDialog.showModal();
        <?php endif; ?>
        <?php if ($openResetDialog): ?>
        resetDialog.showModal();
        <?php endif; ?>
    })();
    </script>
</body>
</html>
