CREATE TABLE `reviews` (
    `review_id` INT(11) NOT NULL AUTO_INCREMENT,
    `appointment_id` INT(11) NOT NULL,
    `user_id` INT(11) NOT NULL,
    `rating` TINYINT UNSIGNED NOT NULL,
    `feedback` TEXT NOT NULL,
    `is_hidden` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`review_id`),
    UNIQUE KEY `uq_reviews_appointment` (`appointment_id`),
    KEY `idx_reviews_user_created` (`user_id`, `created_at`),
    KEY `idx_reviews_rating_created` (`rating`, `created_at`),
    CONSTRAINT `fk_reviews_appointment`
        FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`),
    CONSTRAINT `fk_reviews_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`),
    CONSTRAINT `chk_reviews_rating` CHECK (`rating` BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
