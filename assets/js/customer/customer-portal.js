(() => {
    'use strict';

    const body = document.body;
    const settingsMenu = document.querySelector('.customer-profile-settings-menu');
    const settingsToggle = document.querySelector('.customer-profile-settings-toggle');
    const settingsSubmenu = document.getElementById('customer-profile-settings-submenu');
    const profileDialog = document.getElementById('customer-profile-dialog');
    const settingsDialog = document.getElementById('customer-settings-dialog');
    const profileForm = document.getElementById('customer-profile-dialog-form');
    const profileMessage = document.querySelector('[data-customer-profile-message]');
    const settingsForms = Array.from(document.querySelectorAll('[data-customer-settings-form]'));
    const themeToggle = document.querySelector('[data-customer-theme-toggle]');
    const moonThemeIcon = themeToggle && themeToggle.querySelector('[data-theme-icon="moon"]');
    const sunThemeIcon = themeToggle && themeToggle.querySelector('[data-theme-icon="sun"]');
    const notificationWidget = document.querySelector('[data-customer-notifications]');
    const notificationBell = notificationWidget && notificationWidget.querySelector('.customer-notification-bell');
    const notificationPanel = notificationWidget && notificationWidget.querySelector('.customer-notification-panel');
    const notificationCount = notificationWidget && notificationWidget.querySelector('[data-customer-notification-count]');
    const notificationStatus = notificationWidget && notificationWidget.querySelector('[data-customer-notification-status]');
    const notificationList = notificationWidget && notificationWidget.querySelector('[data-customer-notification-list]');
    const defaultPreferences = {
        appearance: 'light',
        accentColor: '#2b55c9',
        notifications: true,
    };
    const customerId = body.dataset.customerId || 'unknown';
    const preferenceKey = `pawsome.customer.preferences.${customerId}`;

    if (settingsToggle && settingsSubmenu) {
        const hasActiveItem = settingsSubmenu.querySelector('[aria-current="page"]') !== null;
        settingsSubmenu.hidden = !hasActiveItem;
        settingsToggle.setAttribute('aria-expanded', String(hasActiveItem));

        settingsToggle.addEventListener('click', () => {
            const isExpanded = settingsToggle.getAttribute('aria-expanded') === 'true';
            settingsToggle.setAttribute('aria-expanded', String(!isExpanded));
            settingsSubmenu.hidden = isExpanded;
        });

        document.addEventListener('click', (event) => {
            const clickedDialog = event.target instanceof Element
                && event.target.closest('dialog.customer-preferences-dialog');
            if (settingsMenu && !settingsMenu.contains(event.target) && !clickedDialog) {
                settingsSubmenu.hidden = true;
                settingsToggle.setAttribute('aria-expanded', 'false');
            }
        });

        document.addEventListener('keydown', (event) => {
            const dialogOpen = (profileDialog && profileDialog.open) || (settingsDialog && settingsDialog.open);
            if (event.key === 'Escape' && !dialogOpen && !settingsSubmenu.hidden) {
                settingsSubmenu.hidden = true;
                settingsToggle.setAttribute('aria-expanded', 'false');
                settingsToggle.focus();
            }
        });
    }

    function setMessage(element, message, isError) {
        if (!element) {
            return;
        }

        element.classList.toggle('is-error', isError);
        element.textContent = message;
    }

    function closeDialog(button) {
        const dialog = button.closest('dialog');
        if (dialog && dialog.open) {
            dialog.close();
        }
    }

    document.querySelectorAll('[data-customer-dialog-close]').forEach((button) => {
        button.addEventListener('click', () => closeDialog(button));
    });

    document.querySelectorAll('dialog.customer-preferences-dialog').forEach((dialog) => {
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) {
                dialog.close();
            }
        });
    });

    function normalizePreferences(value) {
        if (!value || typeof value !== 'object') {
            return { ...defaultPreferences };
        }

        return {
            appearance: value.appearance === 'dark' ? 'dark' : 'light',
            accentColor: typeof value.accentColor === 'string' && /^#[0-9a-f]{6}$/i.test(value.accentColor)
                && value.accentColor.toLowerCase() !== '#79add0'
                && value.accentColor.toLowerCase() !== '#f77700'
                ? value.accentColor.toLowerCase()
                : defaultPreferences.accentColor,
            notifications: value.notifications !== false,
        };
    }

    function readPreferences() {
        try {
            const stored = window.localStorage.getItem(preferenceKey);
            return {
                preferences: stored === null
                    ? { ...defaultPreferences }
                    : normalizePreferences(JSON.parse(stored)),
                error: '',
            };
        } catch (error) {
            console.error('Could not read customer preferences from this browser.', error);
            return {
                preferences: { ...defaultPreferences },
                error: 'Saved preferences could not be read from this browser.',
            };
        }
    }

    function mixColors(color, other, amount) {
        const first = color.match(/[a-f\d]{2}/gi).map((part) => parseInt(part, 16));
        const second = other.match(/[a-f\d]{2}/gi).map((part) => parseInt(part, 16));
        const result = first.map((channel, index) => Math.round(channel + (second[index] - channel) * amount));
        return `#${result.map((channel) => channel.toString(16).padStart(2, '0')).join('')}`;
    }

    function rgbChannels(color) {
        return color.match(/[a-f\d]{2}/gi).map((part) => parseInt(part, 16));
    }

    function relativeLuminance(color) {
        const channels = rgbChannels(color).map((channel) => {
            const normalized = channel / 255;
            return normalized <= 0.04045 ? normalized / 12.92 : ((normalized + 0.055) / 1.055) ** 2.4;
        });
        return channels[0] * 0.2126 + channels[1] * 0.7152 + channels[2] * 0.0722;
    }

    function applyAccentColor(color) {
        const root = document.documentElement;
        const rgb = rgbChannels(color);
        let readableDeep = color;
        for (let shade = 0; shade < 18 && relativeLuminance(readableDeep) > 0.18; shade += 1) {
            readableDeep = mixColors(readableDeep, '#000000', 0.12);
        }
        let readableLight = color;
        for (let shade = 0; shade < 18 && relativeLuminance(readableLight) < 0.42; shade += 1) {
            readableLight = mixColors(readableLight, '#ffffff', 0.12);
        }
        const isDefaultAccent = color === defaultPreferences.accentColor;
        const textColor = isDefaultAccent
            ? '#ffffff'
            : relativeLuminance(color) > 0.179 ? '#000000' : '#ffffff';
        const palette = {
            '--blue': color,
            '--blue-medium': isDefaultAccent ? '#7392e5' : mixColors(color, '#ffffff', 0.48),
            '--blue-light': isDefaultAccent ? '#dce7ff' : mixColors(color, '#ffffff', 0.8),
            '--blue-deep': isDefaultAccent ? '#2346a8' : readableDeep,
            '--blue-deep-hover': isDefaultAccent ? '#1e3e99' : mixColors(readableDeep, '#000000', 0.12),
            '--ink-on-blue': textColor,
            '--customer-accent': color,
        };
        Object.entries(palette).forEach(([name, value]) => {
            root.style.setProperty(name, value);
            body.style.setProperty(name, value);
        });
        if (isDefaultAccent) {
            readableDeep = '#2346a8';
        }
        const accentRgb = rgb.join(', ');
        root.style.setProperty('--customer-accent-rgb', accentRgb);
        root.style.setProperty('--customer-accent-soft', `rgba(${accentRgb}, .18)`);
        body.style.setProperty('--customer-accent-rgb', accentRgb);
        body.style.setProperty('--customer-accent-soft', `rgba(${accentRgb}, .18)`);
        return { readableDeep, readableLight };
    }

    function updateThemeToggle() {
        if (!themeToggle) {
            return;
        }
        const isDarkMode = preferences.appearance === 'dark';
        const label = isDarkMode ? 'Switch to light mode' : 'Switch to dark mode';
        themeToggle.setAttribute('aria-label', label);
        themeToggle.title = label;
        if (moonThemeIcon) {
            moonThemeIcon.hidden = isDarkMode;
        }
        if (sunThemeIcon) {
            sunThemeIcon.hidden = !isDarkMode;
        }
    }

    function applyPreferences() {
        body.dataset.customerTheme = preferences.appearance;
        const accentShades = applyAccentColor(preferences.accentColor);
        body.style.setProperty(
            '--blue-deep',
            preferences.appearance === 'dark' ? accentShades.readableLight : accentShades.readableDeep
        );
        body.style.setProperty('--customer-accent-rgb', rgbChannels(preferences.accentColor).join(', '));
        body.style.setProperty(
            '--customer-accent-soft',
            `rgba(${rgbChannels(preferences.accentColor).join(', ')}, .18)`
        );
        settingsForms.forEach(updateSettingsForm);
        updateThemeToggle();
        if (notificationCount) {
            notificationCount.hidden = !preferences.notifications
                || notificationCount.textContent === ''
                || notificationCount.textContent === '0';
        }
        if (!preferences.notifications && notificationPanel && !notificationPanel.hidden) {
            showNotificationsDisabled();
        }
    }

    const preferenceState = readPreferences();
    let preferences = preferenceState.preferences;
    applyPreferences();

    function updateSettingsForm(form) {
        form.elements.appearance.value = preferences.appearance;
        form.elements.accent_color.value = preferences.accentColor;
        form.elements.notifications.checked = preferences.notifications;
    }

    settingsForms.forEach((form) => {
        updateSettingsForm(form);
        const message = form.querySelector('[data-customer-settings-message]');
        if (preferenceState.error !== '') {
            setMessage(message, preferenceState.error, true);
        }
    });

    function persistPreferences(message) {
        applyPreferences();
        try {
            window.localStorage.setItem(preferenceKey, JSON.stringify(preferences));
            setMessage(message, 'Preferences saved on this device.', false);
        } catch (error) {
            console.error('Could not save customer preferences to this browser.', error);
            setMessage(message, 'Settings could not be saved in this browser.', true);
        }
        if (preferences.notifications) {
            refreshNotifications();
        } else {
            showNotificationsDisabled();
        }
    }

    function savePreferences(form) {
        preferences = {
            appearance: form.elements.appearance.value === 'dark' ? 'dark' : 'light',
            accentColor: form.elements.accent_color.value,
            notifications: form.elements.notifications.checked,
        };
        persistPreferences(form.querySelector('[data-customer-settings-message]'));
    }

    if (themeToggle) {
        themeToggle.addEventListener('click', () => {
            preferences.appearance = preferences.appearance === 'dark' ? 'light' : 'dark';
            persistPreferences(document.querySelector('[data-customer-theme-status]'));
        });
    }

    settingsForms.forEach((form) => {
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            savePreferences(form);
        });

        const resetButton = form.querySelector('[data-customer-settings-reset]');
        if (resetButton) {
            resetButton.addEventListener('click', () => {
                preferences = { ...defaultPreferences };
                settingsForms.forEach(updateSettingsForm);
                savePreferences(form);
            });
        }
    });

    async function readJsonResponse(response, fallbackMessage) {
        let payload;
        try {
            payload = await response.json();
        } catch (error) {
            throw new Error(fallbackMessage);
        }

        if (!response.ok) {
            throw new Error(payload && typeof payload.error === 'string' ? payload.error : fallbackMessage);
        }

        return payload;
    }

    function setNotificationCount(count) {
        if (!notificationCount || !notificationBell) {
            return;
        }
        const unreadCount = Math.max(0, Number(count) || 0);
        notificationCount.textContent = unreadCount > 99 ? '99+' : String(unreadCount);
        notificationCount.hidden = !preferences.notifications || unreadCount === 0;
        notificationBell.setAttribute(
            'aria-label',
            unreadCount > 0 && preferences.notifications
                ? `Notifications, ${unreadCount} unread`
                : 'Notifications'
        );
    }

    function showNotificationsDisabled() {
        if (!notificationList || !notificationStatus) {
            return;
        }
        notificationList.replaceChildren();
        notificationStatus.textContent = 'Notifications are off. Turn them on in Profile & Settings.';
        setNotificationCount(0);
    }

    function notificationHref(target) {
        if (typeof target !== 'string') {
            return null;
        }
        const customerPath = window.location.pathname.slice(0, window.location.pathname.lastIndexOf('/'));
        let destination;
        try {
            destination = new URL(target, window.location.href);
        } catch (error) {
            return null;
        }
        if (
            destination.origin !== window.location.origin
            || !destination.pathname.startsWith(`${customerPath}/`)
        ) {
            return null;
        }
        const filename = destination.pathname.slice(customerPath.length + 1);
        if (!['appointments.php', 'dashboard.php', 'pets.php', 'reviews.php'].includes(filename)) {
            return null;
        }
        return destination.href;
    }

    function renderNotifications(items) {
        if (!notificationList || !notificationStatus) {
            return;
        }
        notificationList.replaceChildren();
        if (!items.length) {
            notificationStatus.textContent = 'You’re all caught up.';
            return;
        }

        notificationStatus.textContent = '';
        items.forEach((item) => {
            const entry = document.createElement('li');
            entry.className = `customer-notification-item${item.read_at ? '' : ' is-unread'}`;
            const link = document.createElement('a');
            const safeHref = notificationHref(item.target_url);
            if (safeHref) {
                link.href = safeHref;
            } else {
                link.href = '#';
                link.addEventListener('click', (event) => event.preventDefault());
            }

            const title = document.createElement('strong');
            title.textContent = item.title || 'Pawsome update';
            const message = document.createElement('span');
            message.textContent = item.message || '';
            const time = document.createElement('time');
            time.textContent = item.created_label || '';
            link.append(title, message, time);
            entry.append(link);

            if (!item.read_at) {
                const markRead = document.createElement('button');
                markRead.className = 'customer-notification-mark-read';
                markRead.type = 'button';
                markRead.textContent = 'Mark as read';
                markRead.addEventListener('click', () => markNotificationRead(item.notification_id));
                entry.append(markRead);
            }
            notificationList.append(entry);
        });
    }

    async function refreshNotifications() {
        if (!notificationWidget || !preferences.notifications) {
            showNotificationsDisabled();
            return;
        }

        try {
            const response = await fetch(notificationWidget.dataset.api, {
                headers: { Accept: 'application/json' },
                cache: 'no-store',
            });
            const result = await readJsonResponse(response, 'Notifications are temporarily unavailable.');
            setNotificationCount(result.unread_count);
            renderNotifications(Array.isArray(result.notifications) ? result.notifications : []);
        } catch (error) {
            console.error('Could not load customer notifications.', error);
            if (notificationStatus) {
                notificationStatus.textContent = error.message || 'Notifications are temporarily unavailable.';
            }
        }
    }

    async function markNotificationRead(notificationId) {
        if (!notificationWidget || !preferences.notifications) {
            return;
        }
        const body = new URLSearchParams({
            csrf_token: notificationWidget.dataset.csrf,
            notification_id: String(notificationId),
        });
        try {
            const response = await fetch(notificationWidget.dataset.api, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                },
                body,
            });
            await readJsonResponse(response, 'We could not mark this notification as read.');
            await refreshNotifications();
        } catch (error) {
            console.error('Could not mark customer notification as read.', error);
            if (notificationStatus) {
                notificationStatus.textContent = error.message || 'We could not mark this notification as read.';
            }
        }
    }

    async function loadProfile() {
        if (!profileForm || !profileMessage) {
            return;
        }

        setMessage(profileMessage, 'Loading your profile…', false);
        profileForm.querySelectorAll('input:not([type="hidden"])').forEach((input) => {
            input.disabled = true;
        });

        try {
            const response = await fetch('profile.php?dialog=1', {
                headers: { Accept: 'application/json' },
            });
            const payload = await readJsonResponse(response, 'We could not load your profile. Please try again.');
            if (!payload || !payload.profile || typeof payload.profile !== 'object') {
                throw new Error('We could not load your profile. Please try again.');
            }

            ['first_name', 'last_name', 'email', 'username', 'phone'].forEach((field) => {
                profileForm.elements[field].value = payload.profile[field] || '';
            });
            setMessage(profileMessage, '', false);
        } catch (error) {
            console.error('Could not load the customer profile dialog.', error);
            setMessage(profileMessage, error.message, true);
        } finally {
            profileForm.querySelectorAll('input:not([type="hidden"])').forEach((input) => {
                input.disabled = false;
            });
        }
    }

    if (profileForm && profileMessage) {
        profileForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            const submitButton = profileForm.querySelector('button[type="submit"]');
            submitButton.disabled = true;
            setMessage(profileMessage, 'Saving your profile…', false);

            try {
                const response = await fetch(profileForm.action, {
                    method: 'POST',
                    body: new FormData(profileForm),
                    headers: { Accept: 'application/json' },
                });
                const payload = await readJsonResponse(response, 'We could not save your profile. Please try again.');
                if (!payload || typeof payload.message !== 'string' || !payload.profile) {
                    throw new Error('We could not confirm your profile was saved. Please reload and try again.');
                }
                setMessage(profileMessage, payload.message, false);
                const profileName = document.querySelector('[data-customer-profile-name]');
                const profileButton = document.querySelector('[data-customer-profile-button]');
                if (
                    profileName
                    && typeof payload.profile.first_name === 'string'
                    && typeof payload.profile.last_name === 'string'
                ) {
                    const displayName = `${payload.profile.first_name} ${payload.profile.last_name}`.trim();
                    profileName.textContent = displayName || 'Your account';
                    if (profileButton) {
                        profileButton.setAttribute(
                            'aria-label',
                            displayName ? `Welcome back, ${displayName}. Open your profile` : 'Open your profile'
                        );
                    }
                    const dashboardGreeting = document.querySelector('.customer-welcome h1 span');
                    if (dashboardGreeting) {
                        dashboardGreeting.textContent = payload.profile.first_name;
                    }
                }
            } catch (error) {
                console.error('Could not save the customer profile.', error);
                setMessage(profileMessage, error.message, true);
            } finally {
                submitButton.disabled = false;
            }
        });
    }

    document.querySelectorAll('[data-customer-dialog-open]').forEach((button) => {
        button.addEventListener('click', () => {
            const dialog = button.dataset.customerDialogOpen === 'profile'
                ? profileDialog
                : settingsDialog;
            if (!dialog || dialog.open) {
                return;
            }

            dialog.showModal();
            if (dialog === profileDialog) {
                loadProfile();
            }
        });
    });

    if (notificationBell && notificationPanel && notificationWidget) {
        notificationBell.addEventListener('click', () => {
            const shouldOpen = notificationPanel.hidden;
            notificationPanel.hidden = !shouldOpen;
            notificationBell.setAttribute('aria-expanded', String(shouldOpen));
            if (shouldOpen) {
                refreshNotifications();
            }
        });
        const closeButton = notificationWidget.querySelector('[data-customer-notification-close]');
        if (closeButton) {
            closeButton.addEventListener('click', () => {
                notificationPanel.hidden = true;
                notificationBell.setAttribute('aria-expanded', 'false');
                notificationBell.focus();
            });
        }
        document.addEventListener('click', (event) => {
            if (!notificationWidget.contains(event.target) && !notificationPanel.hidden) {
                notificationPanel.hidden = true;
                notificationBell.setAttribute('aria-expanded', 'false');
            }
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !notificationPanel.hidden) {
                notificationPanel.hidden = true;
                notificationBell.setAttribute('aria-expanded', 'false');
                notificationBell.focus();
            }
        });
    }

    window.addEventListener('storage', (event) => {
        if (event.key !== preferenceKey) {
            return;
        }
        try {
            preferences = normalizePreferences(event.newValue ? JSON.parse(event.newValue) : defaultPreferences);
        } catch (error) {
            preferences = { ...defaultPreferences };
        }
        applyPreferences();
        refreshNotifications();
    });

    refreshNotifications();
    window.setInterval(refreshNotifications, 30000);
})();
