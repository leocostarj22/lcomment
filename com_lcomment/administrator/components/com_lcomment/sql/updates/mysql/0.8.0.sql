CREATE TABLE IF NOT EXISTS `#__lcomment_blacklist` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `type` VARCHAR(10) NOT NULL,
    `value` VARCHAR(45) NOT NULL,
    `reason` VARCHAR(255) NULL,
    `created_by` INT UNSIGNED NOT NULL,
    `created` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_type_value` (`type`, `value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
