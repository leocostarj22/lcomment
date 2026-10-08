CREATE TABLE IF NOT EXISTS `#__lcomment_contexts` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `extension` VARCHAR(100) NOT NULL,
    `view` VARCHAR(100) NOT NULL,
    `published` TINYINT NOT NULL DEFAULT 1,
    `moderation` TINYINT NOT NULL DEFAULT 1,
    `scope_mode` VARCHAR(20) NOT NULL DEFAULT 'all',
    `params` TEXT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_extension_view` (`extension`, `view`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `#__lcomment_comments` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `extension` VARCHAR(100) NOT NULL,
    `view` VARCHAR(100) NOT NULL,
    `item_id` INT UNSIGNED NOT NULL,
    `parent_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `user_id` INT UNSIGNED NULL,
    `guest_name` VARCHAR(150) NULL,
    `guest_email` VARCHAR(254) NULL,
    `comment_text` TEXT NOT NULL,
    `state` TINYINT NOT NULL DEFAULT 0,
    `language` CHAR(7) NOT NULL DEFAULT '*',
    `ip` VARCHAR(45) NULL,
    `created` DATETIME NOT NULL,
    `modified` DATETIME NULL,
    PRIMARY KEY (`id`),
    KEY `idx_context_state` (`extension`, `view`, `item_id`, `state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `#__lcomment_reactions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `comment_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NULL,
    `guest_ip` VARCHAR(45) NULL,
    `guest_session_id` VARCHAR(192) NULL,
    `reaction_type` VARCHAR(20) NOT NULL,
    `created` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_comment_id` (`comment_id`),
    UNIQUE KEY `idx_user_comment` (`comment_id`, `user_id`),
    UNIQUE KEY `idx_guest_comment` (`comment_id`, `guest_ip`, `guest_session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
