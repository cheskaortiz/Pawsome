<?php
require_once __DIR__ . '/../includes/auth.php';
auth_require_customer();

$pageTitle = 'Settings';
$activeCustomerPage = 'settings';

require __DIR__ . '/../includes/customer_header.php';
?>
<section class="customer-page-heading">
    <span class="eyebrow">Personalize your portal</span>
    <h1>Settings</h1>
    <p>Choose how Pawsome looks on this device.</p>
</section>

<section class="customer-settings-card" aria-label="Customer settings">
    <form class="customer-settings-form" data-customer-settings-form>
        <div class="customer-settings-form-grid">
            <label class="customer-settings-field" for="customer-appearance">
                <span>Appearance</span>
                <select id="customer-appearance" name="appearance">
                    <option value="light">Light Mode</option>
                    <option value="dark">Dark Mode</option>
                </select>
            </label>
            <label class="customer-settings-field" for="customer-accent-color">
                <span>Accent Color</span>
                <input id="customer-accent-color" name="accent_color" type="color" value="#2b55c9">
            </label>
            <div class="customer-settings-field">
                <span>Notifications</span>
                <label class="customer-settings-switch" for="customer-notifications">
                    <input id="customer-notifications" name="notifications" type="checkbox">
                    <span>Enable notification preferences</span>
                </label>
            </div>
        </div>
        <p class="customer-settings-note">Settings are saved on this browser for your account. In-app updates appear here when available; email and text delivery are not enabled.</p>
        <p class="customer-settings-message" data-customer-settings-message role="status" aria-live="polite"></p>
        <div class="customer-settings-actions">
            <button class="button button-primary" type="submit">Save Settings</button>
            <button class="button pet-cancel-button" type="button" data-customer-settings-reset>Restore Defaults</button>
        </div>
    </form>
</section>
<?php require __DIR__ . '/../includes/customer_footer.php'; ?>
