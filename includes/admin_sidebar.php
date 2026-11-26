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
            <span class="customer-nav-indicator" aria-hidden="true"></span>
            Dashboard
        </a>
        <a
            class="customer-nav-link<?= $activeAdminPage === 'appointments' ? ' is-active' : '' ?>"
            href="appointments.php"
            <?= $activeAdminPage === 'appointments' ? 'aria-current="page"' : '' ?>
        >
            <span class="customer-nav-indicator" aria-hidden="true"></span>
            Appointments
        </a>
        <a
            class="customer-nav-link<?= $activeAdminPage === 'customers' ? ' is-active' : '' ?>"
            href="customers.php"
            <?= $activeAdminPage === 'customers' ? 'aria-current="page"' : '' ?>
        >
            <span class="customer-nav-indicator" aria-hidden="true"></span>
            Customers
        </a>
        <span class="customer-nav-link admin-nav-placeholder" aria-disabled="true" title="Coming soon">
            <span class="customer-nav-indicator" aria-hidden="true"></span>
            Pets
            <span class="admin-nav-coming-soon">Soon</span>
        </span>
        <span class="customer-nav-link admin-nav-placeholder" aria-disabled="true" title="Coming soon">
            <span class="customer-nav-indicator" aria-hidden="true"></span>
            Services &amp; Prices
            <span class="admin-nav-coming-soon">Soon</span>
        </span>
        <span class="customer-nav-link admin-nav-placeholder" aria-disabled="true" title="Coming soon">
            <span class="customer-nav-indicator" aria-hidden="true"></span>
            Grooming Records
            <span class="admin-nav-coming-soon">Soon</span>
        </span>
    </nav>

    <div class="customer-sidebar-bottom">
        <a class="customer-public-link" href="../index.php">← Pawsome website</a>
    </div>
</aside>
