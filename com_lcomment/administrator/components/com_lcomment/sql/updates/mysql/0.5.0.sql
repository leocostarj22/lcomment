ALTER TABLE `#__lcomment_comments`
    ADD COLUMN `item_url` VARCHAR(500) NULL AFTER `ip`;

CREATE TABLE IF NOT EXISTS `#__lcomment_notifications` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `comment_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `subject` VARCHAR(255) NOT NULL,
    `body` TEXT NOT NULL,
    `url` VARCHAR(500) NULL,
    `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `created` DATETIME NOT NULL,
    `sent_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_comment` (`comment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
