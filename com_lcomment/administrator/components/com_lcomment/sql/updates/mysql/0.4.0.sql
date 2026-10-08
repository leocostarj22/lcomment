CREATE TABLE IF NOT EXISTS `#__lcomment_votes` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `comment_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NULL,
    `guest_ip` VARCHAR(45) NULL,
    `guest_session_id` VARCHAR(192) NULL,
    `vote_type` VARCHAR(20) NOT NULL,
    `created` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_comment_id` (`comment_id`),
    UNIQUE KEY `idx_user_comment` (`comment_id`, `user_id`),
    UNIQUE KEY `idx_guest_comment` (`comment_id`, `guest_ip`, `guest_session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
