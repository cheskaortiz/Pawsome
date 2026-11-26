(() => {
    'use strict';

    const notificationWidget = document.querySelector('[data-admin-notifications]');
    const adminPreferenceKey = notificationWidget ? notificationWidget.dataset.adminId : 'admin';
    const preferenceKey = `pawsome.admin.preferences.${adminPreferenceKey}`;
    const defaultPreferences = {
        appearance: 'light',
        accentColor: '#79add0',
        notifications: true,
    };
    const profileDialog = document.getElementById('admin-profile-dialog');
    const settingsDialog = document.getElementById('admin-settings-dialog');
    const submenu = document.getElementById('admin-profile-settings-submenu');
    const submenuToggle = document.querySelector('.admin-profile-settings-toggle');
    const settingsForm = document.getElementById('admin-settings-form');
    const settingsMessage = document.querySelector('[data-settings-message]');
    const profileForm = document.getElementById('admin-profile-form');
    const profileMessage = document.querySelector('[data-profile-message]');
    const notificationBell = notificationWidget && notificationWidget.querySelector('.admin-notification-bell');
    const notificationPanel = notificationWidget && notificationWidget.querySelector('.admin-notification-panel');
    const notificationList = notificationWidget && notificationWidget.querySelector('[data-notification-list]');
    const notificationStatus = notificationWidget && notificationWidget.querySelector('[data-notification-status]');
    const notificationCount = notificationWidget && notificationWidget.querySelector('[data-notification-count]');
    let preferences = readPreferences();

    function normalizePreferences(value) {
        if (!value || typeof value !== 'object') {
            return { ...defaultPreferences };
        }

        return {
            appearance: value.appearance === 'dark' ? 'dark' : 'light',
            accentColor: typeof value.accentColor === 'string' && /^#[0-9a-f]{6}$/i.test(value.accentColor)
                ? value.accentColor.toLowerCase()
                : defaultPreferences.accentColor,
            notifications: value.notifications !== false,
        };
    }

    function readPreferences() {
        try {
            const stored = window.localStorage.getItem(preferenceKey);
            return stored === null ? { ...defaultPreferences } : normalizePreferences(JSON.parse(stored));
        } catch (error) {
            return { ...defaultPreferences };
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
            ? '#30363c'
            : relativeLuminance(color) > 0.179 ? '#000000' : '#ffffff';

        root.style.setProperty('--blue', color);
        root.style.setProperty('--blue-medium', isDefaultAccent ? '#a9cce5' : mixColors(color, '#ffffff', 0.48));
        root.style.setProperty('--blue-light', isDefaultAccent ? '#c9e2f5' : mixColors(color, '#ffffff', 0.8));
        root.style.setProperty('--blue-deep', isDefaultAccent ? '#527d9b' : readableDeep);
        root.style.setProperty(
            '--blue-deep-hover',
            isDefaultAccent ? '#4c7590' : mixColors(readableDeep, '#000000', 0.12)
        );
        if (isDefaultAccent) {
            readableDeep = '#527d9b';
        }
        root.style.setProperty('--ink-on-blue', textColor);
        root.style.setProperty('--admin-accent-rgb', rgb.join(', '));
        root.style.setProperty('--admin-accent-soft', `rgba(${rgb.join(', ')}, .18)`);
        return { readableDeep, readableLight };
    }

    function updatePreferenceControls() {
        if (!settingsForm) {
            return;
        }
        settingsForm.elements.appearance.value = preferences.appearance;
        settingsForm.elements.accent_color.value = preferences.accentColor;
        settingsForm.elements.notifications.checked = preferences.notifications;
    }

    function applyPreferences() {
        document.body.dataset.adminTheme = preferences.appearance;
        const accentShades = applyAccentColor(preferences.accentColor);
        document.body.style.setProperty(
            '--blue-deep',
            preferences.appearance === 'dark' ? accentShades.readableLight : accentShades.readableDeep
        );
        document.body.style.setProperty('--admin-accent-rgb', rgbChannels(preferences.accentColor).join(', '));
        document.body.style.setProperty(
            '--admin-accent-soft',
            `rgba(${rgbChannels(preferences.accentColor).join(', ')}, .18)`
        );
        updatePreferenceControls();
        if (notificationCount) {
            notificationCount.hidden = !preferences.notifications
                || notificationCount.textContent === ''
                || notificationCount.textContent === '0';
        }
        if (!preferences.notifications && notificationPanel && !notificationPanel.hidden) {
            showNotificationsDisabled();
        }
    }

    function savePreferences() {
        applyPreferences();
        try {
            window.localStorage.setItem(preferenceKey, JSON.stringify(preferences));
            if (settingsMessage) {
                settingsMessage.classList.remove('is-error');
                settingsMessage.textContent = 'Preferences saved on this device.';
            }
        } catch (error) {
            if (settingsMessage) {
                settingsMessage.classList.add('is-error');
                settingsMessage.textContent = 'Preferences could not be saved in this browser.';
            }
        }
        refreshNotifications();
    }

    function closeSubmenu() {
        if (submenu && submenuToggle) {
            submenu.hidden = true;
            submenuToggle.setAttribute('aria-expanded', 'false');
        }
    }

    function openDialog(dialog) {
        closeSubmenu();
        if (dialog && !dialog.open) {
            dialog.showModal();
        }
    }

    async function readJsonResponse(response) {
        try {
            return await response.json();
        } catch (error) {
            throw new Error('The server returned an unreadable response.');
        }
    }

    async function loadProfile() {
        if (!profileForm || !profileMessage) {
            return;
        }
        profileMessage.classList.remove('is-error');
        profileMessage.textContent = 'Loading profile…';
        profileForm.querySelectorAll('input:not([type="hidden"]), button[type="submit"]').forEach((field) => {
            field.disabled = true;
        });

        try {
            const response = await fetch('profile-update.php', {
                headers: { Accept: 'application/json' },
                cache: 'no-store',
            });
            const result = await readJsonResponse(response);
            if (!response.ok) {
                throw new Error(result.error || 'We could not load your profile.');
            }
            Object.entries(result.profile).forEach(([name, value]) => {
                if (profileForm.elements[name]) {
                    profileForm.elements[name].value = value || '';
                }
            });
            profileMessage.textContent = '';
        } catch (error) {
            profileMessage.classList.add('is-error');
            profileMessage.textContent = error.message || 'We could not load your profile.';
        } finally {
            profileForm.querySelectorAll('input:not([type="hidden"]), button[type="submit"]').forEach((field) => {
                field.disabled = false;
            });
        }
    }

    async function saveProfile(event) {
        event.preventDefault();
        const submitButton = profileForm.querySelector('button[type="submit"]');
        submitButton.disabled = true;
        profileMessage.classList.remove('is-error');
        profileMessage.textContent = 'Saving profile…';

        try {
            const response = await fetch('profile-update.php', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                },
                body: new URLSearchParams(new FormData(profileForm)),
            });
            const result = await readJsonResponse(response);
            if (!response.ok) {
                throw new Error(result.error || 'We could not save your profile.');
            }
            const greeting = document.querySelector('.admin-topbar-greeting');
            if (greeting) {
                greeting.textContent = `Hello, ${profileForm.elements.first_name.value}`;
            }
            profileMessage.textContent = result.message || 'Your profile was saved.';
        } catch (error) {
            profileMessage.classList.add('is-error');
            profileMessage.textContent = error.message || 'We could not save your profile.';
        } finally {
            submitButton.disabled = false;
        }
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
        const adminPath = window.location.pathname.slice(0, window.location.pathname.lastIndexOf('/'));
        let destination;
        try {
            destination = new URL(target, window.location.href);
        } catch (error) {
            return null;
        }
        if (destination.origin !== window.location.origin || !destination.pathname.startsWith(`${adminPath}/`)) {
            return null;
        }
        const filename = destination.pathname.slice(adminPath.length + 1);
        if (!['appointments.php', 'appointment-details.php', 'reviews.php'].includes(filename)) {
            return null;
        }
        if (filename === 'appointment-details.php' && !/^[1-9]\d*$/.test(destination.searchParams.get('id') || '')) {
            return null;
        }
        return destination.href;
    }

    function renderNotifications(items) {
        notificationList.replaceChildren();
        if (!items.length) {
            notificationStatus.textContent = 'You’re all caught up.';
            return;
        }

        notificationStatus.textContent = '';
        items.forEach((item) => {
            const entry = document.createElement('li');
            entry.className = `admin-notification-item${item.read_at ? '' : ' is-unread'}`;

            const link = document.createElement('a');
            const safeHref = notificationHref(item.target_url);
            if (safeHref) {
                link.href = safeHref;
            } else {
                link.href = '#';
                link.addEventListener('click', (event) => event.preventDefault());
            }

            const title = document.createElement('strong');
            title.textContent = item.title || 'Admin notification';
            const message = document.createElement('span');
            message.textContent = item.message || '';
            const time = document.createElement('time');
            time.textContent = item.created_label || '';
            link.append(title, message, time);
            entry.append(link);

            if (!item.read_at) {
                const markRead = document.createElement('button');
                markRead.className = 'admin-notification-mark-read';
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
            const result = await readJsonResponse(response);
            if (!response.ok) {
                throw new Error(result.error || 'Notifications are temporarily unavailable.');
            }
            setNotificationCount(result.unread_count);
            renderNotifications(Array.isArray(result.notifications) ? result.notifications : []);
        } catch (error) {
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
            const result = await readJsonResponse(response);
            if (!response.ok) {
                throw new Error(result.error || 'We could not mark this notification as read.');
            }
            await refreshNotifications();
        } catch (error) {
            if (notificationStatus) {
                notificationStatus.textContent = error.message || 'We could not mark this notification as read.';
            }
        }
    }

    applyPreferences();

    if (submenuToggle && submenu) {
        submenuToggle.addEventListener('click', () => {
            const isExpanded = submenuToggle.getAttribute('aria-expanded') === 'true';
            submenuToggle.setAttribute('aria-expanded', String(!isExpanded));
            submenu.hidden = isExpanded;
        });
    }

    document.querySelectorAll('[data-admin-dialog-open]').forEach((button) => {
        button.addEventListener('click', () => {
            const dialog = button.dataset.adminDialogOpen === 'profile' ? profileDialog : settingsDialog;
            openDialog(dialog);
            if (dialog === profileDialog) {
                loadProfile();
            }
        });
    });

    document.querySelectorAll('[data-admin-dialog-close]').forEach((button) => {
        button.addEventListener('click', () => {
            const dialog = button.closest('dialog');
            if (dialog) {
                dialog.close();
            }
        });
    });

    document.querySelectorAll('.admin-preferences-dialog').forEach((dialog) => {
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) {
                dialog.close();
            }
        });
    });

    if (profileForm) {
        profileForm.addEventListener('submit', saveProfile);
    }

    if (settingsForm) {
        settingsForm.addEventListener('submit', (event) => event.preventDefault());
        settingsForm.addEventListener('change', (event) => {
            const control = event.target;
            const key = control.dataset.preference;
            if (!key) {
                return;
            }
            preferences[key] = control.type === 'checkbox' ? control.checked : control.value;
            savePreferences();
        });
    }

    const restoreDefaultsButton = document.querySelector('[data-restore-defaults]');
    if (restoreDefaultsButton) {
        restoreDefaultsButton.addEventListener('click', () => {
            preferences = { ...defaultPreferences };
            savePreferences();
        });
    }

    if (notificationBell && notificationPanel) {
        notificationBell.addEventListener('click', () => {
            const shouldOpen = notificationPanel.hidden;
            notificationPanel.hidden = !shouldOpen;
            notificationBell.setAttribute('aria-expanded', String(shouldOpen));
            if (shouldOpen) {
                refreshNotifications();
            }
        });
        notificationWidget.querySelector('.admin-notification-close').addEventListener('click', () => {
            notificationPanel.hidden = true;
            notificationBell.setAttribute('aria-expanded', 'false');
        });
    }

    document.addEventListener('click', (event) => {
        if (submenu && submenuToggle && !event.target.closest('.admin-profile-settings-menu')) {
            closeSubmenu();
        }
        if (
            notificationPanel
            && notificationBell
            && !notificationWidget.contains(event.target)
            && !notificationPanel.hidden
        ) {
            notificationPanel.hidden = true;
            notificationBell.setAttribute('aria-expanded', 'false');
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && notificationPanel && notificationBell && !notificationPanel.hidden) {
            notificationPanel.hidden = true;
            notificationBell.setAttribute('aria-expanded', 'false');
        }
    });

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
