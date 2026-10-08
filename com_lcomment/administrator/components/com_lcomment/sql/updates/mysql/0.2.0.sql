ALTER TABLE `#__lcomment_contexts`
    ADD COLUMN `scope_mode` VARCHAR(20) NOT NULL DEFAULT 'all' AFTER `moderation`;
