-- Run once to add admin-only appointment notes and the grooming workflow statuses.
ALTER TABLE `appointments`
    MODIFY COLUMN `status` ENUM(
        'Pending',
        'Confirmed',
        'In Progress',
        'Ready for Pickup',
        'Completed',
        'Cancelled',
        'Rejected'
    ) DEFAULT 'Pending',
    ADD COLUMN `admin_notes` TEXT NULL DEFAULT NULL AFTER `notes`;
