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
        <span class="customer-nav-link admin-nav-placeholder" aria-disabled="true">
            <svg class="admin-nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 19V5m0 14h17M8 16v-5m5 5V7m5 9V3"/></svg>
            Reports
            <span class="admin-nav-coming-soon">Coming Soon</span>
        </span>
    </nav>

    <nav class="customer-sidebar-bottom admin-sidebar-bottom" aria-label="Admin account navigation">
        <span class="customer-nav-link admin-nav-placeholder" aria-disabled="true">
            <svg class="admin-nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2m7-10a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm11 3a4 4 0 1 0 0 8 4 4 0 0 0 0-8Zm0 1.5v5m-2.5-2.5h5"/></svg>
            User Accounts
            <span class="admin-nav-coming-soon">Coming Soon</span>
        </span>
        <span class="customer-nav-link admin-nav-placeholder" aria-disabled="true">
            <svg class="admin-nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12h4l3-8 4 16 3-8h4"/></svg>
            Activity Log
            <span class="admin-nav-coming-soon">Coming Soon</span>
        </span>
        <span class="customer-nav-link admin-nav-placeholder admin-profile-link" aria-disabled="true">
            <svg class="admin-profile-avatar" viewBox="0 0 32 32" aria-hidden="true"><circle cx="16" cy="16" r="15" fill="currentColor"/><circle cx="16" cy="12" r="5" fill="#fffdf8"/><path d="M6.5 27c1-5.1 4.5-8 9.5-8s8.5 2.9 9.5 8" fill="#fffdf8"/></svg>
            Admin Profile
            <span class="admin-nav-coming-soon">Coming Soon</span>
        </span>
        <span class="customer-nav-link admin-nav-placeholder" aria-disabled="true">
            <svg class="admin-nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 8.5a3.5 3.5 0 1 0 0 7 3.5 3.5 0 0 0 0-7Zm8 3.5-.1-1.5 1.4-1.1-1.5-2.6-1.7.6a7.7 7.7 0 0 0-1.3-.8L16.5 4h-3l-.3 1.8a7.7 7.7 0 0 0-1.4.8l-1.6-.6-1.5 2.6 1.4 1.1a7.7 7.7 0 0 0 0 1.6l-1.4 1.1 1.5 2.6 1.6-.6a7.7 7.7 0 0 0 1.4.8l.3 1.8h3l.3-1.8a7.7 7.7 0 0 0 1.3-.8l1.7.6 1.5-2.6-1.4-1.1L20 12Z"/></svg>
            Settings
            <span class="admin-nav-coming-soon">Coming Soon</span>
        </span>
        <form class="admin-sidebar-logout-form" method="post" action="../customer/logout.php">
            <input type="hidden" name="csrf_token" value="<?= auth_escape(auth_csrf_token()) ?>">
            <button class="customer-nav-link admin-sidebar-logout" type="submit">
                <svg class="admin-nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M10 17 15 12 10 7m5 5H3m9-9h7a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-7"/></svg>
                Logout
            </button>
        </form>
    </nav>
</aside>
