<?php
require_once __DIR__ . '/auth.php';
$pageTitle = isset($pageTitle) && is_string($pageTitle) ? $pageTitle : 'Your Pawsome';
$activeCustomerPage = isset($activeCustomerPage) && is_string($activeCustomerPage) ? $activeCustomerPage : '';
$customerFirstName = trim((string) ($_SESSION['first_name'] ?? ''));
$customerLastName = trim((string) ($_SESSION['last_name'] ?? ''));
$customerDisplayName = trim($customerFirstName . ' ' . $customerLastName);
$customerBreadcrumbPages = [
    'home' => ['label' => 'Home', 'href' => 'dashboard.php'],
    'pets' => ['label' => 'My Pets', 'href' => 'pets.php'],
    'appointments' => ['label' => 'Appointments', 'href' => 'appointments.php'],
    'reviews' => ['label' => 'My Reviews', 'href' => 'reviews.php'],
    'pricelist' => ['label' => 'Pricelist', 'href' => 'pricelist.php'],
    'profile' => ['label' => 'My Profile', 'href' => 'profile.php'],
    'settings' => ['label' => 'Settings', 'href' => 'settings.php'],
];
$customerCurrentScript = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'dashboard.php'));
$customerCurrentQuery = $_SERVER['QUERY_STRING'] ?? '';
$customerCurrentHref = $customerCurrentScript
    . (is_string($customerCurrentQuery) && $customerCurrentQuery !== '' ? '?' . $customerCurrentQuery : '');
$customerDetailBreadcrumbs = [
    'pet-form.php' => ['parent' => 'pets', 'label' => $pageTitle],
    'booking.php' => ['parent' => 'appointments', 'label' => $pageTitle],
    'booking-customer-info.php' => ['parent' => 'appointments', 'label' => $pageTitle],
    'booking-schedule.php' => ['parent' => 'appointments', 'label' => $pageTitle],
    'booking-review.php' => ['parent' => 'appointments', 'label' => $pageTitle],
];
$customerBreadcrumbItems = [
    [
        'label' => 'Home',
        'href' => 'dashboard.php',
        'current' => $activeCustomerPage === 'home',
    ],
];

$customerReviewAction = null;
if ($customerCurrentScript === 'reviews.php') {
    if (isset($_GET['edit'])) {
        $customerReviewAction = 'Edit Review';
    } elseif (isset($_GET['appointment_id'])) {
        $customerReviewAction = 'Write Review';
    }
}

if (isset($customerDetailBreadcrumbs[$customerCurrentScript])) {
    $detailBreadcrumb = $customerDetailBreadcrumbs[$customerCurrentScript];
    $customerBreadcrumbItems[] = $customerBreadcrumbPages[$detailBreadcrumb['parent']];
    $customerBreadcrumbItems[] = [
        'label' => $detailBreadcrumb['label'],
        'href' => $customerCurrentHref,
        'current' => true,
    ];
} elseif ($customerReviewAction !== null) {
    $customerBreadcrumbItems[] = $customerBreadcrumbPages['reviews'];
    $customerBreadcrumbItems[] = [
        'label' => $customerReviewAction,
        'href' => $customerCurrentHref,
        'current' => true,
    ];
} elseif ($activeCustomerPage !== 'home') {
    $currentBreadcrumb = $customerBreadcrumbPages[$activeCustomerPage] ?? [
        'label' => $pageTitle,
        'href' => $customerCurrentHref,
    ];
    $customerBreadcrumbItems[] = [
        'label' => $currentBreadcrumb['label'],
        'href' => $customerCurrentHref,
        'current' => true,
    ];
}
$customerNavigation = [
    'home' => ['Home', 'dashboard.php', 'home'],
    'pets' => ['My Pets', 'pets.php', 'pets'],
    'appointments' => ['Appointments', 'appointments.php', 'appointments'],
    'reviews' => ['My Reviews', 'reviews.php', 'reviews'],
    'pricelist' => ['Pricelist', 'pricelist.php', 'pricelist'],
];
$customerIcons = [
    'home' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>',
    'pets' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 15.5c-3.2 0-6.3 2.1-6.3 4.4 0 1.4 1.4 2.1 3 1.5 1.2-.5 2.1-.8 3.3-.8s2.1.3 3.3.8c1.6.6 3-.1 3-1.5 0-2.3-3.1-4.4-6.3-4.4Z"/><ellipse cx="5.5" cy="10" rx="2.4" ry="3.2"/><ellipse cx="9.5" cy="5.7" rx="2.3" ry="3.1"/><ellipse cx="14.5" cy="5.7" rx="2.3" ry="3.1"/><ellipse cx="18.5" cy="10" rx="2.4" ry="3.2"/></svg>',
    'appointments' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="3"/><path d="M7 3v4M17 3v4M3 10h18"/><path d="m8 15 2 2 5-5"/></svg>',
    'reviews' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3 2.7 5.5 6.1.9-4.4 4.3 1 6.1-5.4-2.9-5.4 2.9 1-6.1-4.4-4.3 6.1-.9Z"/></svg>',
    'pricelist' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v14H4zM8 9h8M8 13h8M8 17h5"/></svg>',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Your Pawsome customer dashboard.">
    <meta name="theme-color" content="#fbf8ff">
    <title><?= auth_escape($pageTitle) ?> | Pawsome</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800;900&family=Fredoka:wght@500;600;700&family=Nunito:wght@500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/shared/portal.css">
    <link rel="stylesheet" href="../assets/css/customer/customer.css">
    <?php if ($activeCustomerPage === 'reviews'): ?>
        <link rel="stylesheet" href="../assets/css/customer/reviews.css">
    <?php endif; ?>
</head>
<body class="customer-app customer-page--<?= auth_escape($activeCustomerPage) ?>" data-customer-id="<?= (int) ($_SESSION['user_id'] ?? 0) ?>">
    <a class="skip-link" href="#customer-main">Skip to content</a>
    <div class="customer-layout">
        <aside class="customer-sidebar">
            <a class="brand customer-brand" href="dashboard.php" aria-label="Pawsome customer home">
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

            <nav class="customer-navigation" aria-label="Customer navigation">
                <?php foreach ($customerNavigation as $key => [$label, $href, $icon]): ?>
                    <a class="customer-nav-link<?= $activeCustomerPage === $key ? ' is-active' : '' ?>" href="<?= auth_escape($href) ?>"<?= $activeCustomerPage === $key ? ' aria-current="page"' : '' ?>>
                        <span class="customer-nav-icon" aria-hidden="true"><?= $customerIcons[$icon] ?></span>
                        <span><?= auth_escape($label) ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
            <?php if (!in_array($customerCurrentScript, ['booking.php', 'booking-customer-info.php', 'booking-schedule.php', 'booking-review.php'], true)): ?>
                <a class="customer-sidebar-book-link" href="booking.php">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="3"/><path d="M7 3v4M17 3v4M3 10h18M12 13v5M9.5 15.5h5"/></svg>
                    <span>Book Now</span>
                </a>
            <?php endif; ?>

            <div class="customer-sidebar-bottom">
                <div class="customer-profile-settings-menu">
                    <button class="customer-nav-link customer-profile-settings-toggle" type="button" aria-expanded="false" aria-controls="customer-profile-settings-submenu">
                        <svg class="customer-profile-avatar" viewBox="0 0 32 32" aria-hidden="true"><circle cx="16" cy="16" r="15" fill="currentColor"/><circle cx="16" cy="12" r="5" fill="#fff"/><path d="M6.5 27c1-5.1 4.5-8 9.5-8s8.5 2.9 9.5 8" fill="#fff"/></svg>
                        Profile &amp; Settings
                        <svg class="customer-profile-settings-chevron" viewBox="0 0 16 16" aria-hidden="true"><path d="m4 6 4 4 4-4"/></svg>
                    </button>
                    <div class="customer-profile-settings-submenu" id="customer-profile-settings-submenu" hidden>
                        <button class="customer-nav-link" type="button" data-customer-dialog-open="profile">My Profile</button>
                        <button class="customer-nav-link" type="button" data-customer-dialog-open="settings">Settings</button>
                    </div>
                </div>
                <form class="customer-sidebar-logout-form" method="post" action="logout.php">
                    <input type="hidden" name="csrf_token" value="<?= auth_escape(auth_csrf_token()) ?>">
                    <button class="customer-nav-link customer-sidebar-logout" type="submit">
                        <span class="customer-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M10 17 15 12 10 7m5 5H3m9-9h7a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-7"/></svg></span>
                        Logout
                    </button>
                </form>
            </div>
        </aside>

        <div class="customer-main-column">
            <header class="customer-topbar">
                <nav class="customer-breadcrumb-header" aria-label="Breadcrumb">
                    <ol class="customer-breadcrumb-list">
                        <?php foreach ($customerBreadcrumbItems as $index => $breadcrumb): ?>
                            <?php if ($index > 0): ?>
                                <li class="customer-breadcrumb-separator" aria-hidden="true">›</li>
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
                <div class="customer-header-actions">
                    <button class="customer-header-icon-button customer-theme-toggle" type="button" data-customer-theme-toggle aria-label="Switch to dark mode" title="Switch to dark mode">
                        <svg viewBox="0 0 24 24" aria-hidden="true" data-theme-icon="moon">
                            <path d="M20.2 15.1A8.5 8.5 0 0 1 8.9 3.8 8.7 8.7 0 1 0 20.2 15.1Z"></path>
                        </svg>
                        <svg viewBox="0 0 24 24" aria-hidden="true" data-theme-icon="sun" hidden>
                            <circle cx="12" cy="12" r="4"></circle>
                            <path d="M12 2v2m0 16v2M4.93 4.93l1.42 1.42m11.3 11.3 1.42 1.42M2 12h2m16 0h2M4.93 19.07l1.42-1.42m11.3-11.3 1.42-1.42"></path>
                        </svg>
                    </button>
                    <span class="customer-visually-hidden" data-customer-theme-status role="status" aria-live="polite"></span>
                    <div class="customer-notification-widget" data-customer-notifications data-api="notifications.php" data-csrf="<?= auth_escape(auth_csrf_token()) ?>">
                        <button class="customer-header-icon-button customer-notification-bell" type="button" aria-label="Notifications" aria-expanded="false" aria-controls="customer-notification-panel">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9m-8 12a2 2 0 0 0 4 0"/></svg>
                            <span class="customer-notification-count" data-customer-notification-count hidden></span>
                        </button>
                        <section class="customer-notification-panel" id="customer-notification-panel" aria-label="Notifications" hidden>
                            <header class="customer-notification-heading">
                                <h2>Notifications</h2>
                                <button class="customer-notification-close" type="button" data-customer-notification-close aria-label="Close notifications">&times;</button>
                            </header>
                            <p class="customer-notification-status" data-customer-notification-status role="status" aria-live="polite"></p>
                            <ul class="customer-notification-list" data-customer-notification-list></ul>
                        </section>
                    </div>
                    <button class="customer-profile-chip" type="button" data-customer-dialog-open="profile" data-customer-profile-button aria-label="<?= auth_escape($customerDisplayName !== '' ? 'Welcome back, ' . $customerDisplayName . '. Open your profile' : 'Open your profile') ?>">
                        <span class="customer-profile-chip-avatar" aria-hidden="true">
                            <svg viewBox="0 0 32 32"><circle cx="16" cy="16" r="15" fill="currentColor"/><circle cx="16" cy="12" r="5" fill="#fff"/><path d="M6.5 27c1-5.1 4.5-8 9.5-8s8.5 2.9 9.5 8" fill="#fff"/></svg>
                        </span>
                        <span class="customer-profile-chip-copy">
                            <small>Welcome back</small>
                            <strong data-customer-profile-name><?= auth_escape($customerDisplayName !== '' ? $customerDisplayName : 'Your account') ?></strong>
                        </span>
                    </button>
                </div>
            </header>
            <main class="customer-main" id="customer-main">
