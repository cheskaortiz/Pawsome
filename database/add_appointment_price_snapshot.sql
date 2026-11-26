-- Run once to preserve the service price when each new appointment is confirmed.
-- Existing appointments are intentionally left without a snapshot because their
-- original prices were not recorded and cannot be inferred from today's catalog.
ALTER TABLE `appointments`
    ADD COLUMN `service_price_at_booking` DECIMAL(10,2) NULL AFTER `service_id`,
    ADD COLUMN `service_price_snapshot_saved` TINYINT(1) NOT NULL DEFAULT 0
        AFTER `service_price_at_booking`;
