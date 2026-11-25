-- Run once to store an optional pickup date and time for each appointment.
ALTER TABLE `appointments`
    ADD COLUMN `pickup_date` DATE NULL DEFAULT NULL AFTER `appointment_time`,
    ADD COLUMN `pickup_time` TIME NULL DEFAULT NULL AFTER `pickup_date`;
