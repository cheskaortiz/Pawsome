-- Run once after add_grooming_service_catalog.sql.
-- Existing services remain linked to historical appointments but leave the active catalog.
ALTER TABLE `services`
    MODIFY COLUMN `species` ENUM('Dog', 'Cat', 'Other') NOT NULL,
    MODIFY COLUMN `price` DECIMAL(10,2) NULL,
    ADD COLUMN `pet_size` VARCHAR(20) NOT NULL DEFAULT 'Any' AFTER `species`,
    ADD COLUMN `price_note` VARCHAR(50) NULL AFTER `price`,
    DROP INDEX `uq_services_species_category_name`,
    ADD UNIQUE KEY `uq_services_species_category_name_size`
        (`species`, `category`, `service_name`, `pet_size`);

UPDATE `services`
SET `status` = 'Unavailable';

INSERT INTO `services`
    (`species`, `pet_size`, `category`, `service_name`, `description`, `price`, `price_note`, `duration`, `status`)
VALUES
    ('Dog', 'Small', 'Basic Groom', 'Small Dog', 'Basic shampoo bath, brushing, blow dry, simple body haircut/trim, sanitary trim, nail clipping, ear cleaning, teeth brushing, powder, and cologne. Toothbrush must be provided by the owner or purchased from the shop. Additional charges may apply for matted hair.', 600.00, NULL, 60, 'Available'),
    ('Dog', 'Medium', 'Basic Groom', 'Medium Dog', 'Basic shampoo bath, brushing, blow dry, simple body haircut/trim, sanitary trim, nail clipping, ear cleaning, teeth brushing, powder, and cologne. Toothbrush must be provided by the owner or purchased from the shop. Additional charges may apply for matted hair.', 700.00, NULL, 60, 'Available'),
    ('Dog', 'Large', 'Basic Groom', 'Large Dog', 'Basic shampoo bath, brushing, blow dry, simple body haircut/trim, sanitary trim, nail clipping, ear cleaning, teeth brushing, powder, and cologne. Toothbrush must be provided by the owner or purchased from the shop. Additional charges may apply for matted hair.', 800.00, NULL, 90, 'Available'),
    ('Dog', 'Giant', 'Basic Groom', 'Giant Dog', 'Basic shampoo bath, brushing, blow dry, simple body haircut/trim, sanitary trim, nail clipping, ear cleaning, teeth brushing, powder, and cologne. Toothbrush must be provided by the owner or purchased from the shop. Additional charges may apply for matted hair.', 1000.00, NULL, 90, 'Available'),
    ('Cat', 'Any', 'Basic Groom', 'Cats & Other Small Animals', 'Basic shampoo bath, brushing, blow dry, simple body haircut/trim, sanitary trim, nail clipping, ear cleaning, teeth brushing, powder, and cologne. Toothbrush must be provided by the owner or purchased from the shop. Additional charges may apply for matted hair.', NULL, NULL, 60, 'Available'),
    ('Other', 'Any', 'Basic Groom', 'Cats & Other Small Animals', 'Basic shampoo bath, brushing, blow dry, simple body haircut/trim, sanitary trim, nail clipping, ear cleaning, teeth brushing, powder, and cologne. Toothbrush must be provided by the owner or purchased from the shop. Additional charges may apply for matted hair.', NULL, NULL, 60, 'Available'),

    ('Dog', 'Small', 'Premium Groom', 'Small Dog', 'Premium shampoo bath, brushing, blow dry, body scissoring/shaping, sanitary trim, nail clipping, ear cleaning, teeth brushing, powder, cologne, anal sac draining, mini pet massage, and tear stain removal. Toothbrush must be provided by the owner or purchased from the shop. Additional charges may apply for matted hair.', 750.00, NULL, 90, 'Available'),
    ('Dog', 'Medium', 'Premium Groom', 'Medium Dog', 'Premium shampoo bath, brushing, blow dry, body scissoring/shaping, sanitary trim, nail clipping, ear cleaning, teeth brushing, powder, cologne, anal sac draining, mini pet massage, and tear stain removal. Toothbrush must be provided by the owner or purchased from the shop. Additional charges may apply for matted hair.', 850.00, NULL, 90, 'Available'),
    ('Dog', 'Large', 'Premium Groom', 'Large Dog', 'Premium shampoo bath, brushing, blow dry, body scissoring/shaping, sanitary trim, nail clipping, ear cleaning, teeth brushing, powder, cologne, anal sac draining, mini pet massage, and tear stain removal. Toothbrush must be provided by the owner or purchased from the shop. Additional charges may apply for matted hair.', 950.00, NULL, 120, 'Available'),
    ('Dog', 'Giant', 'Premium Groom', 'Giant Dog', 'Premium shampoo bath, brushing, blow dry, body scissoring/shaping, sanitary trim, nail clipping, ear cleaning, teeth brushing, powder, cologne, anal sac draining, mini pet massage, and tear stain removal. Toothbrush must be provided by the owner or purchased from the shop. Additional charges may apply for matted hair.', 1150.00, NULL, 120, 'Available'),
    ('Cat', 'Any', 'Premium Groom', 'Cats & Other Small Animals', 'Premium shampoo bath, brushing, blow dry, body scissoring/shaping, sanitary trim, nail clipping, ear cleaning, teeth brushing, powder, cologne, anal sac draining, mini pet massage, and tear stain removal. Toothbrush must be provided by the owner or purchased from the shop. Additional charges may apply for matted hair.', 750.00, NULL, 90, 'Available'),
    ('Other', 'Any', 'Premium Groom', 'Cats & Other Small Animals', 'Premium shampoo bath, brushing, blow dry, body scissoring/shaping, sanitary trim, nail clipping, ear cleaning, teeth brushing, powder, cologne, anal sac draining, mini pet massage, and tear stain removal. Toothbrush must be provided by the owner or purchased from the shop. Additional charges may apply for matted hair.', 750.00, NULL, 90, 'Available'),

    ('Dog', 'Any', 'A La Carte Services (Flat Rate)', 'Anal Sac Draining', 'Anal sac draining service.', 150.00, NULL, 15, 'Available'),
    ('Cat', 'Any', 'A La Carte Services (Flat Rate)', 'Anal Sac Draining', 'Anal sac draining service.', 150.00, NULL, 15, 'Available'),
    ('Other', 'Any', 'A La Carte Services (Flat Rate)', 'Anal Sac Draining', 'Anal sac draining service.', 150.00, NULL, 15, 'Available'),
    ('Dog', 'Any', 'A La Carte Services (Flat Rate)', 'Anti-Tick and Flea Application', 'Application of anti-tick and flea treatment.', 50.00, NULL, 10, 'Available'),
    ('Cat', 'Any', 'A La Carte Services (Flat Rate)', 'Anti-Tick and Flea Application', 'Application of anti-tick and flea treatment.', 50.00, NULL, 10, 'Available'),
    ('Other', 'Any', 'A La Carte Services (Flat Rate)', 'Anti-Tick and Flea Application', 'Application of anti-tick and flea treatment.', 50.00, NULL, 10, 'Available'),
    ('Dog', 'Any', 'A La Carte Services (Flat Rate)', 'Dematting', 'Dematting service. Starting price; additional charges may apply.', 200.00, 'Starting price', 30, 'Available'),
    ('Cat', 'Any', 'A La Carte Services (Flat Rate)', 'Dematting', 'Dematting service. Starting price; additional charges may apply.', 200.00, 'Starting price', 30, 'Available'),
    ('Other', 'Any', 'A La Carte Services (Flat Rate)', 'Dematting', 'Dematting service. Starting price; additional charges may apply.', 200.00, 'Starting price', 30, 'Available'),
    ('Dog', 'Any', 'A La Carte Services (Flat Rate)', 'Ear Cleaning', 'Ear cleaning service.', 150.00, NULL, 15, 'Available'),
    ('Cat', 'Any', 'A La Carte Services (Flat Rate)', 'Ear Cleaning', 'Ear cleaning service.', 150.00, NULL, 15, 'Available'),
    ('Other', 'Any', 'A La Carte Services (Flat Rate)', 'Ear Cleaning', 'Ear cleaning service.', 150.00, NULL, 15, 'Available'),
    ('Dog', 'Any', 'A La Carte Services (Flat Rate)', 'Eye Wash / Tear Stain Removal', 'Eye wash and tear stain removal service.', 150.00, NULL, 10, 'Available'),
    ('Cat', 'Any', 'A La Carte Services (Flat Rate)', 'Eye Wash / Tear Stain Removal', 'Eye wash and tear stain removal service.', 150.00, NULL, 10, 'Available'),
    ('Other', 'Any', 'A La Carte Services (Flat Rate)', 'Eye Wash / Tear Stain Removal', 'Eye wash and tear stain removal service.', 150.00, NULL, 10, 'Available'),
    ('Dog', 'Any', 'A La Carte Services (Flat Rate)', 'Face Trimming', 'Face trimming service.', 200.00, NULL, 15, 'Available'),
    ('Cat', 'Any', 'A La Carte Services (Flat Rate)', 'Face Trimming', 'Face trimming service.', 200.00, NULL, 15, 'Available'),
    ('Other', 'Any', 'A La Carte Services (Flat Rate)', 'Face Trimming', 'Face trimming service.', 200.00, NULL, 15, 'Available'),
    ('Dog', 'Any', 'A La Carte Services (Flat Rate)', 'Nail Clipping', 'Nail clipping service.', 150.00, NULL, 15, 'Available'),
    ('Cat', 'Any', 'A La Carte Services (Flat Rate)', 'Nail Clipping', 'Nail clipping service.', 150.00, NULL, 15, 'Available'),
    ('Other', 'Any', 'A La Carte Services (Flat Rate)', 'Nail Clipping', 'Nail clipping service.', 150.00, NULL, 15, 'Available'),
    ('Dog', 'Any', 'A La Carte Services (Flat Rate)', 'Paw Trimming', 'Paw trimming service.', 150.00, NULL, 10, 'Available'),
    ('Cat', 'Any', 'A La Carte Services (Flat Rate)', 'Paw Trimming', 'Paw trimming service.', 150.00, NULL, 10, 'Available'),
    ('Other', 'Any', 'A La Carte Services (Flat Rate)', 'Paw Trimming', 'Paw trimming service.', 150.00, NULL, 10, 'Available'),
    ('Dog', 'Any', 'A La Carte Services (Flat Rate)', 'Teeth Brushing', 'Teeth brushing service.', 150.00, NULL, 15, 'Available'),
    ('Cat', 'Any', 'A La Carte Services (Flat Rate)', 'Teeth Brushing', 'Teeth brushing service.', 150.00, NULL, 15, 'Available'),
    ('Other', 'Any', 'A La Carte Services (Flat Rate)', 'Teeth Brushing', 'Teeth brushing service.', 150.00, NULL, 15, 'Available'),
    ('Dog', 'Any', 'A La Carte Services (Flat Rate)', 'Medicated Bath', 'Medicated bath service.', 250.00, NULL, 30, 'Available'),
    ('Cat', 'Any', 'A La Carte Services (Flat Rate)', 'Medicated Bath', 'Medicated bath service.', 250.00, NULL, 30, 'Available'),
    ('Other', 'Any', 'A La Carte Services (Flat Rate)', 'Medicated Bath', 'Medicated bath service.', 250.00, NULL, 30, 'Available'),
    ('Dog', 'Any', 'A La Carte Services (Flat Rate)', 'Handling Fee', 'Handling fee. This service is unavailable until its price is provided.', NULL, NULL, 30, 'Unavailable'),
    ('Cat', 'Any', 'A La Carte Services (Flat Rate)', 'Handling Fee', 'Handling fee. This service is unavailable until its price is provided.', NULL, NULL, 30, 'Unavailable'),
    ('Other', 'Any', 'A La Carte Services (Flat Rate)', 'Handling Fee', 'Handling fee. This service is unavailable until its price is provided.', NULL, NULL, 30, 'Unavailable'),

    ('Dog', 'Small', 'A La Carte Services (Size-Dependent)', 'Basic Bath & Blowdry', 'Basic bath and blow-dry service.', 300.00, NULL, 30, 'Available'),
    ('Dog', 'Medium', 'A La Carte Services (Size-Dependent)', 'Basic Bath & Blowdry', 'Basic bath and blow-dry service.', 400.00, NULL, 30, 'Available'),
    ('Dog', 'Large', 'A La Carte Services (Size-Dependent)', 'Basic Bath & Blowdry', 'Basic bath and blow-dry service.', 500.00, NULL, 45, 'Available'),
    ('Dog', 'Giant', 'A La Carte Services (Size-Dependent)', 'Basic Bath & Blowdry', 'Basic bath and blow-dry service.', 600.00, NULL, 45, 'Available'),
    ('Cat', 'Any', 'A La Carte Services (Size-Dependent)', 'Basic Bath & Blowdry', 'Basic bath and blow-dry service.', 250.00, NULL, 30, 'Available'),
    ('Other', 'Any', 'A La Carte Services (Size-Dependent)', 'Basic Bath & Blowdry', 'Basic bath and blow-dry service.', 250.00, NULL, 30, 'Available'),
    ('Dog', 'Small', 'A La Carte Services (Size-Dependent)', 'Haircut / Trim', 'Summer cut, coat edge trim, semi-kalbo, and kalbo.', 350.00, NULL, 30, 'Available'),
    ('Dog', 'Medium', 'A La Carte Services (Size-Dependent)', 'Haircut / Trim', 'Summer cut, coat edge trim, semi-kalbo, and kalbo.', 400.00, NULL, 30, 'Available'),
    ('Dog', 'Large', 'A La Carte Services (Size-Dependent)', 'Haircut / Trim', 'Summer cut, coat edge trim, semi-kalbo, and kalbo.', 450.00, NULL, 45, 'Available'),
    ('Dog', 'Giant', 'A La Carte Services (Size-Dependent)', 'Haircut / Trim', 'Summer cut, coat edge trim, semi-kalbo, and kalbo.', 500.00, NULL, 45, 'Available'),
    ('Cat', 'Any', 'A La Carte Services (Size-Dependent)', 'Haircut / Trim', 'Summer cut, coat edge trim, semi-kalbo, and kalbo.', 300.00, NULL, 30, 'Available'),
    ('Other', 'Any', 'A La Carte Services (Size-Dependent)', 'Haircut / Trim', 'Summer cut, coat edge trim, semi-kalbo, and kalbo.', 300.00, NULL, 30, 'Available'),
    ('Dog', 'Small', 'A La Carte Services (Size-Dependent)', 'Sanitary Trim (Paws, Rear, Privates)', 'Sanitary trim for paws, rear, and privates.', 150.00, NULL, 15, 'Available'),
    ('Dog', 'Medium', 'A La Carte Services (Size-Dependent)', 'Sanitary Trim (Paws, Rear, Privates)', 'Sanitary trim for paws, rear, and privates.', 170.00, NULL, 15, 'Available'),
    ('Dog', 'Large', 'A La Carte Services (Size-Dependent)', 'Sanitary Trim (Paws, Rear, Privates)', 'Sanitary trim for paws, rear, and privates.', 190.00, NULL, 15, 'Available'),
    ('Dog', 'Giant', 'A La Carte Services (Size-Dependent)', 'Sanitary Trim (Paws, Rear, Privates)', 'Sanitary trim for paws, rear, and privates.', 210.00, NULL, 15, 'Available'),
    ('Cat', 'Any', 'A La Carte Services (Size-Dependent)', 'Sanitary Trim (Paws, Rear, Privates)', 'Sanitary trim for paws, rear, and privates.', 150.00, NULL, 15, 'Available'),
    ('Other', 'Any', 'A La Carte Services (Size-Dependent)', 'Sanitary Trim (Paws, Rear, Privates)', 'Sanitary trim for paws, rear, and privates.', 150.00, NULL, 15, 'Available')
ON DUPLICATE KEY UPDATE
    `description` = VALUES(`description`),
    `price` = VALUES(`price`),
    `price_note` = VALUES(`price_note`),
    `duration` = VALUES(`duration`),
    `status` = VALUES(`status`);
