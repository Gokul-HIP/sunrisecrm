-- =============================================================================
-- Content Calendar: Special Day Notices Deduplication & Performance Indexes
-- Compatible with MySQL 8.0.46+ strict and MariaDB 10.11+
-- DO NOT USE INFORMATION_SCHEMA (production permissions restriction)
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. Deduplication Table: rise_cc_special_day_notices
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rise_cc_special_day_notices` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `notice_date` DATE NOT NULL,
  `label_key` VARCHAR(190) NOT NULL,
  `label` VARCHAR(255) NOT NULL,
  `notified_at` DATETIME NULL,
  `recipients` INT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cc_sdn_date_key` (`notice_date`, `label_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 2. Performance Index on rise_cc_special_days(special_date)
-- Check first:
--   SHOW INDEX FROM `rise_cc_special_days` WHERE Column_name = 'special_date' AND Seq_in_index = 1;
-- If empty (no leading special_date index exists), run:
-- -----------------------------------------------------------------------------
ALTER TABLE `rise_cc_special_days` ADD INDEX `idx_cc_special_days_date` (`special_date`);

-- -----------------------------------------------------------------------------
-- VERIFICATION QUERIES:
-- -----------------------------------------------------------------------------
-- SHOW CREATE TABLE `rise_cc_special_day_notices`;
-- SHOW INDEX FROM `rise_cc_special_days` WHERE Key_name = 'idx_cc_special_days_date';

-- -----------------------------------------------------------------------------
-- ROLLBACK SCRIPT (Run only if reversing this migration):
-- -----------------------------------------------------------------------------
-- DROP TABLE IF EXISTS `rise_cc_special_day_notices`;
-- ALTER TABLE `rise_cc_special_days` DROP INDEX `idx_cc_special_days_date`;
