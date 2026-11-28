            </main>
            <dialog class="customer-preferences-dialog" id="customer-profile-dialog" aria-labelledby="customer-profile-dialog-title">
                <form class="customer-preferences-form" id="customer-profile-dialog-form" method="post" action="profile.php?dialog=1">
                    <header class="customer-preferences-heading">
                        <div>
                            <span class="eyebrow">Your account</span>
                            <h2 id="customer-profile-dialog-title">My Profile</h2>
                            <p>Update your profile information.</p>
                        </div>
                        <button class="customer-preferences-close" type="button" data-customer-dialog-close aria-label="Close profile dialog">&times;</button>
                    </header>
                    <p class="customer-preferences-message" data-customer-profile-message role="status" aria-live="polite"></p>
                    <div class="customer-preferences-fields">
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
                    <input type="hidden" name="dialog" value="1">
                    <footer class="customer-preferences-actions">
                        <button class="customer-preferences-secondary" type="button" data-customer-dialog-close>Cancel</button>
                        <button class="button button-primary" type="submit">Save changes</button>
                    </footer>
                </form>
            </dialog>

            <dialog class="customer-preferences-dialog" id="customer-settings-dialog" aria-labelledby="customer-settings-dialog-title">
                <form class="customer-preferences-form" data-customer-settings-form>
                    <header class="customer-preferences-heading">
                        <div>
                            <span class="eyebrow">Personalize your portal</span>
                            <h2 id="customer-settings-dialog-title">Settings</h2>
                        </div>
                        <button class="customer-preferences-close" type="button" data-customer-dialog-close aria-label="Close settings dialog">&times;</button>
                    </header>
                    <div class="customer-preferences-fields">
                        <label>
                            <span>Appearance</span>
                            <select name="appearance">
                                <option value="light">Light Mode</option>
                                <option value="dark">Dark Mode</option>
                            </select>
                        </label>
                        <label>
                            <span>Accent Color</span>
                            <input name="accent_color" type="color" value="#2b55c9">
                        </label>
                        <label class="customer-preference-switch">
                            <span>Notifications</span>
                            <input name="notifications" type="checkbox">
                            <span class="customer-preference-switch-track" aria-hidden="true"></span>
                        </label>
                    </div>
                    <p class="customer-preferences-message" data-customer-settings-message role="status" aria-live="polite"></p>
                    <p class="customer-preferences-note">Settings are saved on this browser for your account. In-app updates appear here when available; email and text delivery are not enabled.</p>
                    <footer class="customer-preferences-actions">
                        <button class="customer-preferences-secondary" type="button" data-customer-settings-reset>Restore Defaults</button>
                        <button class="button button-primary" type="submit">Save Settings</button>
                    </footer>
                </form>
            </dialog>
            <footer class="customer-footer">
                <p>&copy; <?= date('Y') ?> Pawsome. Made with care for pets and their people.</p>
            </footer>
        </div>
    </div>
    <script src="../assets/js/customer/customer-portal.js" defer></script>
</body>
</html>
