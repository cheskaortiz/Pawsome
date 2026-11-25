-- Run once to add species/category fields and the approved academic-project catalog.
ALTER TABLE `services`
    ADD COLUMN `species` ENUM('Dog', 'Cat') NOT NULL AFTER `service_id`,
    ADD COLUMN `category` VARCHAR(50) NOT NULL AFTER `species`,
    ADD UNIQUE KEY `uq_services_species_category_name` (`species`, `category`, `service_name`);

INSERT INTO `services` (`species`, `category`, `service_name`, `description`, `price`, `duration`, `status`) VALUES
    ('Dog', 'Basic Bath', 'Shampoo and Bath', 'Basic shampoo and bathing service for dogs.', 250.00, 60, 'Available'),
    ('Dog', 'Basic Bath', 'Blow Dry', 'Drying the dog''s coat after bathing.', 100.00, 30, 'Available'),
    ('Dog', 'Basic Bath', 'Brushing and Detangling', 'Brushing and removing minor tangles from the dog''s coat.', 150.00, 30, 'Available'),
    ('Dog', 'Basic Bath', 'Basic Ear Cleaning', 'Basic external ear cleaning for dogs.', 100.00, 15, 'Available'),
    ('Dog', 'Full Grooming', 'Bath and Blow Dry', 'Complete bathing and blow-drying service.', 350.00, 90, 'Available'),
    ('Dog', 'Full Grooming', 'Haircut and Styling', 'Grooming haircut and coat styling.', 450.00, 90, 'Available'),
    ('Dog', 'Full Grooming', 'Nail Trimming', 'Trimming the dog''s nails.', 100.00, 15, 'Available'),
    ('Dog', 'Full Grooming', 'Ear Cleaning', 'Ear cleaning as part of a full grooming session.', 100.00, 15, 'Available'),
    ('Dog', 'Full Grooming', 'Sanitary Trim', 'Trimming hair around sanitary areas.', 150.00, 30, 'Available'),
    ('Dog', 'Haircut & Styling', 'Full Haircut', 'General haircut for dogs.', 350.00, 60, 'Available'),
    ('Dog', 'Haircut & Styling', 'Breed-Specific Haircut', 'Haircut based on the dog''s breed and coat style.', 500.00, 90, 'Available'),
    ('Dog', 'Haircut & Styling', 'Puppy Cut', 'Rounded, even haircut for dogs.', 400.00, 75, 'Available'),
    ('Dog', 'Haircut & Styling', 'Face and Paw Trimming', 'Trimming excess hair around the face and paws.', 200.00, 30, 'Available'),
    ('Dog', 'Nail Trimming', 'Nail Clipping', 'Clipping the dog''s nails.', 100.00, 15, 'Available'),
    ('Dog', 'Nail Trimming', 'Nail Filing', 'Smoothing the dog''s nails.', 100.00, 15, 'Available'),
    ('Cat', 'Basic Bath', 'Shampoo and Bath', 'Basic shampoo and bathing service for cats.', 350.00, 60, 'Available'),
    ('Cat', 'Basic Bath', 'Blow Dry', 'Drying the cat''s coat after bathing.', 150.00, 30, 'Available'),
    ('Cat', 'Basic Bath', 'Brushing and Detangling', 'Brushing and removing minor tangles from the cat''s coat.', 200.00, 30, 'Available'),
    ('Cat', 'Basic Bath', 'Basic Ear Cleaning', 'Basic external ear cleaning for cats.', 100.00, 15, 'Available'),
    ('Cat', 'Full Grooming', 'Bath and Blow Dry', 'Complete bathing and blow-drying service.', 500.00, 90, 'Available'),
    ('Cat', 'Full Grooming', 'Haircut and Styling', 'Grooming haircut and coat styling.', 600.00, 90, 'Available'),
    ('Cat', 'Full Grooming', 'Nail Trimming', 'Trimming the cat''s nails.', 150.00, 15, 'Available'),
    ('Cat', 'Full Grooming', 'Ear Cleaning', 'Ear cleaning as part of a full grooming session.', 100.00, 15, 'Available'),
    ('Cat', 'Full Grooming', 'Sanitary Trim', 'Trimming hair around sanitary areas.', 200.00, 30, 'Available'),
    ('Cat', 'Haircut & Styling', 'Full Haircut', 'General haircut for cats.', 500.00, 60, 'Available'),
    ('Cat', 'Haircut & Styling', 'Breed-Specific Haircut', 'Haircut based on the cat''s breed and coat style.', 650.00, 90, 'Available'),
    ('Cat', 'Haircut & Styling', 'Puppy Cut', 'Rounded, even haircut for cats.', 550.00, 75, 'Available'),
    ('Cat', 'Haircut & Styling', 'Face and Paw Trimming', 'Trimming excess hair around the face and paws.', 250.00, 30, 'Available'),
    ('Cat', 'Nail Trimming', 'Nail Clipping', 'Clipping the cat''s nails.', 150.00, 15, 'Available'),
    ('Cat', 'Nail Trimming', 'Nail Filing', 'Smoothing the cat''s nails.', 150.00, 15, 'Available')
ON DUPLICATE KEY UPDATE
    `description` = VALUES(`description`),
    `price` = VALUES(`price`),
    `duration` = VALUES(`duration`),
    `status` = 'Available';
