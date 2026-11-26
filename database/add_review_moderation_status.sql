ALTER TABLE `reviews`
    ADD COLUMN `status` ENUM('Pending', 'Approved', 'Removed') NOT NULL DEFAULT 'Pending' AFTER `is_hidden`,
    ADD COLUMN `status_before_removal` ENUM('Pending', 'Approved') DEFAULT NULL AFTER `status`;

UPDATE `reviews`
SET `status` = 'Removed',
    `status_before_removal` = 'Pending'
WHERE `is_hidden` = 1;

UPDATE `reviews`
SET `is_hidden` = 1
WHERE `status` <> 'Approved';
