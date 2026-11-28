<?php
$adminSidebarFirstName = trim((string) ($_SESSION['first_name'] ?? 'Admin'));
$adminSidebarDisplayName = trim($adminSidebarFirstName . ' ' . (string) ($_SESSION['last_name'] ?? ''));
if ($adminSidebarDisplayName === '') {
    $adminSidebarDisplayName = 'Admin';
}

$adminBreadcrumbPages = [
    'dashboard' => ['label' => 'Dashboard', 'href' => 'dashboard.php'],
    'appointments' => ['label' => 'Appointments', 'href' => 'appointments.php'],
    'customers' => ['label' => 'Customers', 'href' => 'customers.php'],
    'pets' => ['label' => 'Pets', 'href' => 'pets.php'],
    'services' => ['label' => 'Services & Prices', 'href' => 'services.php'],
    'grooming-records' => ['label' => 'Grooming Records', 'href' => 'grooming-records.php'],
    'staff' => ['label' => 'Groomers / Staff', 'href' => 'staff.php'],
    'reviews' => ['label' => 'Reviews', 'href' => 'reviews.php'],
    'reports' => ['label' => 'Reports', 'href' => 'reports.php'],
    'activity-log' => ['label' => 'Activity Log', 'href' => 'activity-log.php'],
    'user-accounts' => ['label' => 'User Accounts', 'href' => 'user-accounts.php'],
];
$adminDetailBreadcrumbs = [
    'appointment-details.php' => ['parent' => 'appointments', 'label' => 'Appointment Details'],
    'customer-details.php' => ['parent' => 'customers', 'label' => 'Customer Details'],
    'pet-details.php' => ['parent' => 'pets', 'label' => 'Pet Details'],
    'grooming-record-details.php' => ['parent' => 'grooming-records', 'label' => 'Grooming Record Details'],
    'staff-details.php' => ['parent' => 'staff', 'label' => 'Staff Details'],
];
$adminCurrentScript = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'dashboard.php'));
$adminCurrentQuery = $_SERVER['QUERY_STRING'] ?? '';
$adminCurrentHref = $adminCurrentScript
    . (is_string($adminCurrentQuery) && $adminCurrentQuery !== '' ? '?' . $adminCurrentQuery : '');
$adminBreadcrumbItems = [
    ['label' => 'Pawsome', 'href' => 'dashboard.php'],
];
if (isset($adminDetailBreadcrumbs[$adminCurrentScript])) {
    $detailBreadcrumb = $adminDetailBreadcrumbs[$adminCurrentScript];
    $parentBreadcrumb = $adminBreadcrumbPages[$detailBreadcrumb['parent']];
    $adminBreadcrumbItems[] = $parentBreadcrumb;
    $adminBreadcrumbItems[] = [
        'label' => $detailBreadcrumb['label'],
        'href' => $adminCurrentHref,
        'current' => true,
    ];
} elseif (isset($adminBreadcrumbPages[$activeAdminPage])) {
    $adminBreadcrumbItems[] = [
        'label' => $adminBreadcrumbPages[$activeAdminPage]['label'],
        'href' => $adminCurrentHref,
        'current' => true,
    ];
} else {
    $adminBreadcrumbItems[] = [
        'label' => 'Admin',
        'href' => $adminCurrentHref,
        'current' => true,
    ];
}
?>
<aside class="customer-sidebar admin-sidebar">
    <a class="brand customer-brand" href="dashboard.php" aria-label="Pawsome admin home">
        <span class="brand-mark" aria-hidden="true">
            <svg viewBox="0 0 40 40" focusable="false">
                <path d="M20 31.3c-1.6 0-3.1-1.1-5.4-1.1-2.1 0-3.6 1-5.3.2-2.4-1.2-2.1-4.7.6-7.5 2.4-2.5 6-4.4 10.1-4.4s7.7 1.9 10.1 4.4c2.7 2.8 3 6.3.6 7.5-1.7.8-3.2-.2-5.3-.2-2.3 0-3.8 1.1-5.4 1.1Z" fill="currentColor"/>
                <ellipse cx="9.8" cy="15.3" rx="3.3" ry="4.3" transform="rotate(-27 9.8 15.3)" fill="currentColor"/>
                <ellipse cx="17.1" cy="10.6" rx="3.2" ry="4.2" transform="rotate(-10 17.1 10.6)" fill="currentColor"/>
                <ellipse cx="24.9" cy="10.6" rx="3.2" ry="4.2" transform="rotate(10 24.9 10.6)" fill="currentColor"/>
                <ellipse cx="32.2" cy="15.3" rx="3.3" ry="4.3" transform="rotate(27 32.2 15.3)" fill="currentColor"/>
            </svg>
        </span>
        <span class="brand-name">Paw<span>some</span></span>
    </a>

    <nav class="customer-navigation admin-navigation" aria-label="Admin navigation">
        <a
            class="customer-nav-link<?= $activeAdminPage === 'dashboard' ? ' is-active' : '' ?>"
            href="dashboard.php"
            <?= $activeAdminPage === 'dashboard' ? 'aria-current="page"' : '' ?>
        >
            <svg class="admin-nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 13h8V3H3v10Zm0 8h8v-5H3v5Zm10 0h8V11h-8v10Zm0-18v5h8V3h-8Z"/></svg>
            Dashboard
        </a>
        <a
            class="customer-nav-link<?= $activeAdminPage === 'appointments' ? ' is-active' : '' ?>"
            href="appointments.php"
            <?= $activeAdminPage === 'appointments' ? 'aria-current="page"' : '' ?>
        >
            <svg class="admin-nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 2v4m10-4v4M4 9h16M5 4h14a1 1 0 0 1 1 1v15H4V5a1 1 0 0 1 1-1Zm3 9h3v3H8v-3Z"/></svg>
            Appointments
        </a>
        <a
            class="customer-nav-link<?= $activeAdminPage === 'customers' ? ' is-active' : '' ?>"
            href="customers.php"
            <?= $activeAdminPage === 'customers' ? 'aria-current="page"' : '' ?>
        >
            <svg class="admin-nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2m7-10a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm13 10v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            Customers
        </a>
        <a
            class="customer-nav-link<?= $activeAdminPage === 'pets' ? ' is-active' : '' ?>"
            href="pets.php"
            <?= $activeAdminPage === 'pets' ? 'aria-current="page"' : '' ?>
        >
            <svg class="admin-nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20.5c-1.3 0-2.2-.8-3.7-.8-1.1 0-2.1.5-3.1.1-1.7-.7-1.5-3.1.2-5.1 1.5-1.8 3.9-3.2 6.6-3.2s5.1 1.4 6.6 3.2c1.7 2 1.9 4.4.2 5.1-1 .4-2-.1-3.1-.1-1.5 0-2.4.8-3.7.8ZM5.3 9.8c-1.3-.2-2.1-1.7-1.8-3.3.3-1.5 1.5-2.6 2.8-2.4 1.3.2 2.1 1.7 1.8 3.3-.3 1.5-1.5 2.6-2.8 2.4Zm13.4 0c-1.3.2-2.5-.9-2.8-2.4-.3-1.6.5-3.1 1.8-3.3 1.3-.2 2.5.9 2.8 2.4.3 1.6-.5 3.1-1.8 3.3ZM10 8.3c-1.3 0-2.3-1.4-2.3-3.1S8.7 2 10 2s2.3 1.4 2.3 3.2S11.3 8.3 10 8.3Zm4 0c-1.3 0-2.3-1.4-2.3-3.1S12.7 2 14 2s2.3 1.4 2.3 3.2S15.3 8.3 14 8.3Z"/></svg>
            Pets
        </a>
        <a
            class="customer-nav-link<?= $activeAdminPage === 'services' ? ' is-active' : '' ?>"
            href="services.php"
            <?= $activeAdminPage === 'services' ? 'aria-current="page"' : '' ?>
        >
            <svg class="admin-nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3a3 3 0 1 0 0 6 3 3 0 0 0 0-6Zm12 12a3 3 0 1 0 0 6 3 3 0 0 0 0-6ZM8.1 8.1l10.8 10.8M18 3 6 15m5-5 3 3"/></svg>
            Services &amp; Prices
        </a>
        <a
            class="customer-nav-link<?= $activeAdminPage === 'grooming-records' ? ' is-active' : '' ?>"
            href="grooming-records.php"
            <?= $activeAdminPage === 'grooming-records' ? 'aria-current="page"' : '' ?>
        >
            <svg class="admin-nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3h10a2 2 0 0 1 2 2v16H5V5a2 2 0 0 1 2-2Zm3 0V2h4v1M8 9h8m-8 4h8m-8 4h5"/></svg>
            Grooming Records
        </a>
        <a
            class="customer-nav-link<?= $activeAdminPage === 'staff' ? ' is-active' : '' ?>"
            href="staff.php"
            <?= $activeAdminPage === 'staff' ? 'aria-current="page"' : '' ?>
        >
            <svg class="admin-nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2m7-10a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm13 10v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            Groomers / Staff
        </a>
        <a
            class="customer-nav-link<?= $activeAdminPage === 'reviews' ? ' is-active' : '' ?>"
            href="reviews.php"
            <?= $activeAdminPage === 'reviews' ? 'aria-current="page"' : '' ?>
        >
            <svg class="admin-nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-3-5.6 3 1.1-6.2L3 9.6l6.2-.9L12 3Z"/></svg>
            Reviews
        </a>
        <a
            class="customer-nav-link<?= $activeAdminPage === 'reports' ? ' is-active' : '' ?>"
            href="reports.php"
            <?= $activeAdminPage === 'reports' ? 'aria-current="page"' : '' ?>
        >
            <svg class="admin-nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 19V5m0 14h17M8 16v-5m5 5V7m5 9V3"/></svg>
            Reports
        </a>
    </nav>

    <nav class="customer-sidebar-bottom admin-sidebar-bottom" aria-label="Admin account navigation">
        <a
            class="customer-nav-link<?= $activeAdminPage === 'user-accounts' ? ' is-active' : '' ?>"
            href="user-accounts.php"
            <?= $activeAdminPage === 'user-accounts' ? 'aria-current="page"' : '' ?>
        >
            <svg class="admin-nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2m7-10a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm11 3a4 4 0 1 0 0 8 4 4 0 0 0 0-8Zm0 1.5v5m-2.5-2.5h5"/></svg>
            User Accounts
        </a>
        <a
            class="customer-nav-link<?= $activeAdminPage === 'activity-log' ? ' is-active' : '' ?>"
            href="activity-log.php"
            <?= $activeAdminPage === 'activity-log' ? 'aria-current="page"' : '' ?>
        >
            <svg class="admin-nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12h4l3-8 4 16 3-8h4"/></svg>
            Activity Log
        </a>
        <div class="admin-profile-settings-menu">
            <button class="customer-nav-link admin-profile-settings-toggle" type="button" aria-expanded="false" aria-controls="admin-profile-settings-submenu">
                <svg class="admin-profile-avatar" viewBox="0 0 32 32" aria-hidden="true"><circle cx="16" cy="16" r="15" fill="currentColor"/><circle cx="16" cy="12" r="5" fill="#fff"/><path d="M6.5 27c1-5.1 4.5-8 9.5-8s8.5 2.9 9.5 8" fill="#fff"/></svg>
                Profile &amp; Settings
                <svg class="admin-profile-settings-chevron" viewBox="0 0 16 16" aria-hidden="true"><path d="m4 6 4 4 4-4"/></svg>
            </button>
            <div class="admin-profile-settings-submenu" id="admin-profile-settings-submenu" hidden>
                <button class="customer-nav-link" type="button" data-admin-dialog-open="profile">Admin Profile</button>
                <button class="customer-nav-link" type="button" data-admin-dialog-open="settings">Settings</button>
            </div>
        </div>
        <form class="admin-sidebar-logout-form" method="post" action="../customer/logout.php">
            <input type="hidden" name="csrf_token" value="<?= auth_escape(auth_csrf_token()) ?>">
            <button class="customer-nav-link admin-sidebar-logout" type="submit">
                <svg class="admin-nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M10 17 15 12 10 7m5 5H3m9-9h7a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-7"/></svg>
                Logout
            </button>
        </form>
    </nav>
</aside>

<div class="admin-header-row">
    <nav class="admin-breadcrumb-header" aria-label="Breadcrumb">
        <ol class="admin-breadcrumb-list">
            <?php foreach ($adminBreadcrumbItems as $index => $breadcrumb): ?>
                <?php if ($index > 0): ?>
                    <li class="admin-breadcrumb-separator" aria-hidden="true">›</li>
                <?php endif; ?>
                <li>
                    <a
                        href="<?= auth_escape($breadcrumb['href']) ?>"
                        <?= !empty($breadcrumb['current']) ? 'aria-current="page"' : '' ?>
                    ><?= auth_escape($breadcrumb['label']) ?></a>
                </li>
            <?php endforeach; ?>
        </ol>
    </nav>

    <div class="admin-notification-widget" data-admin-notifications data-admin-id="<?= (int) $_SESSION['user_id'] ?>" data-api="notifications.php" data-csrf="<?= auth_escape(auth_csrf_token()) ?>">
        <button class="admin-theme-toggle" type="button" data-admin-theme-toggle aria-label="Switch to dark mode" title="Switch to dark mode">
            <svg viewBox="0 0 24 24" aria-hidden="true" data-theme-icon="moon">
                <path d="M20.2 15.1A8.5 8.5 0 0 1 8.9 3.8 8.7 8.7 0 1 0 20.2 15.1Z"></path>
            </svg>
            <svg viewBox="0 0 24 24" aria-hidden="true" data-theme-icon="sun" hidden>
                <circle cx="12" cy="12" r="4"></circle>
                <path d="M12 2v2m0 16v2M4.93 4.93l1.42 1.42m11.3 11.3 1.42 1.42M2 12h2m16 0h2M4.93 19.07l1.42-1.42m11.3-11.3 1.42-1.42"></path>
            </svg>
        </button>
        <button class="admin-notification-bell" type="button" aria-label="Notifications" aria-expanded="false" aria-controls="admin-notification-panel">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9m-8 12a2 2 0 0 0 4 0"/></svg>
            <span class="admin-notification-count" data-notification-count hidden></span>
        </button>
        <button class="admin-profile-chip" type="button" data-admin-dialog-open="profile">
            <span class="admin-profile-chip-avatar" aria-hidden="true">
                <svg viewBox="0 0 32 32"><circle cx="16" cy="16" r="15" fill="currentColor"/><circle cx="16" cy="12" r="5" fill="#fff"/><path d="M6.5 27c1-5.1 4.5-8 9.5-8s8.5 2.9 9.5 8" fill="#fff"/></svg>
            </span>
            <span class="admin-profile-chip-copy">
                <small>Welcome back</small>
                <strong class="admin-profile-chip-name"><?= auth_escape($adminSidebarDisplayName) ?></strong>
            </span>
        </button>
        <section class="admin-notification-panel" id="admin-notification-panel" aria-label="Recent notifications" hidden>
            <header class="admin-notification-heading">
                <h2>Notifications</h2>
                <button class="admin-notification-close" type="button" aria-label="Close notifications">&times;</button>
            </header>
            <p class="admin-notification-status" data-notification-status role="status" aria-live="polite"></p>
            <ul class="admin-notification-list" data-notification-list></ul>
        </section>
    </div>
</div>

<dialog class="admin-preferences-dialog" id="admin-profile-dialog" aria-labelledby="admin-profile-dialog-title">
    <form class="admin-preferences-form" id="admin-profile-form">
        <header class="admin-preferences-heading">
            <div>
                <span class="eyebrow">Admin account</span>
                <h2 id="admin-profile-dialog-title">Admin Profile</h2>
                <p>Update your profile information.</p>
            </div>
            <button class="admin-preferences-close" type="button" data-admin-dialog-close aria-label="Close profile dialog">&times;</button>
        </header>
        <p class="admin-preferences-message" data-profile-message role="status" aria-live="polite"></p>
        <div class="admin-preferences-fields">
            <label>
                <span>First name</span>
                <input name="first_name" type="text" maxlength="100" autocomplete="given-name" required>
            </label>
            <label>
                <span>Last name</span>
                <input name="last_name" type="text" maxlength="100" autocomplete="family-name" required>
            </label>
            <label>
                <span>Email address</span>
                <input name="email" type="email" maxlength="150" autocomplete="email" required>
            </label>
            <label>
                <span>Username</span>
                <input name="username" type="text" maxlength="50" autocomplete="username" required>
            </label>
            <label>
                <span>Phone number</span>
                <input name="phone" type="tel" maxlength="20" autocomplete="tel">
            </label>
        </div>
        <input type="hidden" name="csrf_token" value="<?= auth_escape(auth_csrf_token()) ?>">
        <footer class="admin-preferences-actions">
            <button class="admin-preferences-secondary" type="button" data-admin-dialog-close>Cancel</button>
            <button class="button button-primary" type="submit">Save changes</button>
        </footer>
    </form>
</dialog>

<dialog class="admin-preferences-dialog" id="admin-settings-dialog" aria-labelledby="admin-settings-dialog-title">
    <form class="admin-preferences-form" id="admin-settings-form">
        <header class="admin-preferences-heading">
            <div>
                <span class="eyebrow">Personalize your dashboard</span>
                <h2 id="admin-settings-dialog-title">Settings</h2>
            </div>
            <button class="admin-preferences-close" type="button" data-admin-dialog-close aria-label="Close settings dialog">&times;</button>
        </header>
        <div class="admin-preferences-fields">
            <label>
                <span>Appearance</span>
                <select name="appearance" data-preference="appearance">
                    <option value="light">Light Mode</option>
                    <option value="dark">Dark Mode</option>
                </select>
            </label>
            <label>
                <span>Accent Color</span>
                <input name="accent_color" type="color" value="#2b55c9" data-preference="accentColor">
            </label>
            <label class="admin-preference-switch">
                <span>Notifications</span>
                <input name="notifications" type="checkbox" data-preference="notifications">
                <span class="admin-preference-switch-track" aria-hidden="true"></span>
            </label>
        </div>
        <p class="admin-preferences-message" data-settings-message role="status" aria-live="polite"></p>
        <footer class="admin-preferences-actions">
            <button class="admin-preferences-secondary" type="button" data-restore-defaults>Restore Defaults</button>
            <button class="button button-primary" type="button" data-admin-dialog-close>Done</button>
        </footer>
    </form>
</dialog>

<script src="../assets/js/admin/admin-dashboard.js" defer></script>
