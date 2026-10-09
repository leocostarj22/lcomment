CREATE TABLE IF NOT EXISTS `#__lcomment_group_permissions` (
    `group_id` INT UNSIGNED NOT NULL,
    `can_post` TINYINT NOT NULL DEFAULT 0,
    `can_reply` TINYINT NOT NULL DEFAULT 0,
    `autopublish` TINYINT NOT NULL DEFAULT 0,
    `can_edit_own` TINYINT NOT NULL DEFAULT 0,
    `can_delete_own` TINYINT NOT NULL DEFAULT 0,
    PRIMARY KEY (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
