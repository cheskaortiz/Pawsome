<?php
require_once __DIR__ . '/auth.php';
$pageTitle = isset($pageTitle) && is_string($pageTitle) ? $pageTitle : 'Your Pawsome';
$activeCustomerPage = isset($activeCustomerPage) && is_string($activeCustomerPage) ? $activeCustomerPage : '';
$customerFirstName = (string) ($_SESSION['first_name'] ?? 'pet parent');
$customerNavigation = [
    'home' => ['Home', 'dashboard.php'],
    'pets' => ['My Pets', 'pets.php'],
    'appointments' => ['Appointments', 'appointments.php'],
    'reviews' => ['My Reviews', 'reviews.php'],
    'pricelist' => ['Pricelist', 'pricelist.php'],
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
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/shared/portal.css">
    <link rel="stylesheet" href="../assets/css/customer/customer.css">
    <?php if ($activeCustomerPage === 'reviews'): ?>
        <link rel="stylesheet" href="../assets/css/customer/reviews.css">
    <?php endif; ?>
</head>
<body class="customer-app">
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
                <?php foreach ($customerNavigation as $key => [$label, $href]): ?>
                    <a class="customer-nav-link<?= $activeCustomerPage === $key ? ' is-active' : '' ?>" href="<?= auth_escape($href) ?>"<?= $activeCustomerPage === $key ? ' aria-current="page"' : '' ?>>
                        <span class="customer-nav-indicator" aria-hidden="true"></span>
                        <?= auth_escape($label) ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="customer-sidebar-bottom">
                <a class="customer-public-link" href="../index.php">← Pawsome website</a>
                <form method="post" action="logout.php">
                    <input type="hidden" name="csrf_token" value="<?= auth_escape(auth_csrf_token()) ?>">
                    <button class="customer-logout" type="submit">Log out</button>
                </form>
            </div>
        </aside>

        <div class="customer-main-column">
            <header class="customer-topbar">
                <div>
                    <span class="customer-topbar-label">Your Pawsome space</span>
                    <p class="customer-topbar-greeting">Hello, <?= auth_escape($customerFirstName) ?></p>
                </div>
                <span class="customer-topbar-paw" aria-hidden="true">♥</span>
            </header>
            <main class="customer-main" id="customer-main">
