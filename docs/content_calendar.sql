-- ============================================================================
-- Content Calendar module (RISE CRM) - SQL (phase 1 + phase 2 import)
-- Target: MySQL 8.0 (strict mode) and MariaDB 10.11. Prefix: rise_
--
-- The WHOLE file is idempotent. Run it as it is:
--   * fresh database          : PART A creates all 8 tables, PART B finds nothing to do.
--   * phase 1 already present : PART A creates only rise_cc_import_logs, PART B adds
--                               rise_cc_items.import_batch_id and its index.
-- There is NO seed row. Nothing existing is dropped or rewritten.
-- ============================================================================

-- ============================================================================
-- PART A - fresh install (CREATE TABLE IF NOT EXISTS)
-- ============================================================================

-- 1) Calendars (one per client) ----------------------------------------------
CREATE TABLE IF NOT EXISTS `rise_cc_calendars` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(120) NOT NULL,
  `client_id` INT(11) NULL DEFAULT NULL,
  `sort_order` INT(11) NOT NULL DEFAULT 0,
  `deleted` TINYINT(1) NOT NULL DEFAULT 0,
  `created_by` INT(11) NOT NULL DEFAULT 0,
  `created_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cc_calendars_deleted_sort` (`deleted`, `sort_order`),
  KEY `idx_cc_calendars_client` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) Columns of each calendar -------------------------------------------------
CREATE TABLE IF NOT EXISTS `rise_cc_columns` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `calendar_id` INT(11) NOT NULL,
  `field_key` VARCHAR(80) NOT NULL,
  `label` VARCHAR(120) NOT NULL,
  `input_type` VARCHAR(20) NOT NULL DEFAULT 'text',
  `options` JSON NULL,
  `is_core` TINYINT(1) NOT NULL DEFAULT 0,
  `is_visible` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT(11) NOT NULL DEFAULT 0,
  `deleted` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cc_columns_calendar_key` (`calendar_id`, `field_key`),
  KEY `idx_cc_columns_calendar_sort` (`calendar_id`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) Content items (phase 2: import_batch_id marks the rows of one import) -----------
CREATE TABLE IF NOT EXISTS `rise_cc_items` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `calendar_id` INT(11) NOT NULL,
  `publish_date` DATE NOT NULL,
  `content_type` VARCHAR(60) NOT NULL DEFAULT '',
  `status` VARCHAR(60) NOT NULL DEFAULT '',
  `title` VARCHAR(255) NOT NULL DEFAULT '',
  `values_json` JSON NULL,
  `deleted` TINYINT(1) NOT NULL DEFAULT 0,
  `created_by` INT(11) NOT NULL DEFAULT 0,
  `updated_by` INT(11) NOT NULL DEFAULT 0,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  `import_batch_id` VARCHAR(40) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cc_items_calendar_date` (`calendar_id`, `publish_date`),
  KEY `idx_cc_items_calendar_type_status` (`calendar_id`, `content_type`, `status`),
  KEY `idx_cc_items_import_batch` (`import_batch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4) Months shown in the month row ----------------------------------------------
CREATE TABLE IF NOT EXISTS `rise_cc_months` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `calendar_id` INT(11) NOT NULL,
  `year` SMALLINT(6) NOT NULL,
  `month` TINYINT(4) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cc_months_calendar_ym` (`calendar_id`, `year`, `month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5) Special days ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rise_cc_special_days` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `calendar_id` INT(11) NOT NULL,
  `special_date` DATE NOT NULL,
  `label` VARCHAR(120) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cc_special_days_calendar_date` (`calendar_id`, `special_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6) Monthly targets per content type (summary tiles only) --------------------------
CREATE TABLE IF NOT EXISTS `rise_cc_targets` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `calendar_id` INT(11) NOT NULL,
  `content_type` VARCHAR(60) NOT NULL,
  `target_count` INT(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cc_targets_calendar_type` (`calendar_id`, `content_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7) Permissions (view, add, edit, delete, manage_options, add_client, add_column) -------
CREATE TABLE IF NOT EXISTS `rise_cc_permissions` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `permission_key` VARCHAR(30) NOT NULL,
  `user_id` INT(11) NOT NULL,
  `created_by` INT(11) NOT NULL DEFAULT 0,
  `created_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cc_permissions_key_user` (`permission_key`, `user_id`),
  KEY `idx_cc_permissions_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8) Import log (phase 2): one row per import into one client ------------------------------
CREATE TABLE IF NOT EXISTS `rise_cc_import_logs` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `calendar_id` INT(11) NOT NULL,
  `user_id` INT(11) NOT NULL DEFAULT 0,
  `file_name` VARCHAR(255) NOT NULL DEFAULT '',
  `sheet_name` VARCHAR(120) NOT NULL DEFAULT '',
  `rows_total` INT(11) NOT NULL DEFAULT 0,
  `rows_imported` INT(11) NOT NULL DEFAULT 0,
  `rows_skipped` INT(11) NOT NULL DEFAULT 0,
  `columns_created` INT(11) NOT NULL DEFAULT 0,
  `options_created` INT(11) NOT NULL DEFAULT 0,
  `batch_id` VARCHAR(40) NOT NULL,
  `undone` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cc_import_logs_batch` (`batch_id`),
  KEY `idx_cc_import_logs_calendar` (`calendar_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- PART B - upgrade for a database that already has phase 1 (idempotent)
-- MySQL 8 has no ADD COLUMN IF NOT EXISTS, so INFORMATION_SCHEMA + PREPARE / EXECUTE is used.
-- ============================================================================

-- B1) rise_cc_import_logs: already created by PART A (CREATE TABLE IF NOT EXISTS).

-- B2) rise_cc_items.import_batch_id (nullable)
SET @cc_col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rise_cc_items' AND COLUMN_NAME = 'import_batch_id');
SET @cc_sql := IF(@cc_col_exists = 0, 'ALTER TABLE `rise_cc_items` ADD COLUMN `import_batch_id` VARCHAR(40) NULL DEFAULT NULL', 'SELECT 1');
PREPARE cc_stmt FROM @cc_sql;
EXECUTE cc_stmt;
DEALLOCATE PREPARE cc_stmt;

-- B3) index on rise_cc_items.import_batch_id
SET @cc_idx_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rise_cc_items' AND INDEX_NAME = 'idx_cc_items_import_batch');
SET @cc_sql := IF(@cc_idx_exists = 0, 'ALTER TABLE `rise_cc_items` ADD INDEX `idx_cc_items_import_batch` (`import_batch_id`)', 'SELECT 1');
PREPARE cc_stmt FROM @cc_sql;
EXECUTE cc_stmt;
DEALLOCATE PREPARE cc_stmt;

-- ============================================================================
-- VERIFICATION QUERIES (read only, safe to run any time)
-- ============================================================================
-- SHOW TABLES LIKE 'rise\_cc\_%';
--
-- SELECT 'rise_cc_calendars' AS t, COUNT(*) AS n FROM rise_cc_calendars
-- UNION ALL SELECT 'rise_cc_columns', COUNT(*) FROM rise_cc_columns
-- UNION ALL SELECT 'rise_cc_items', COUNT(*) FROM rise_cc_items
-- UNION ALL SELECT 'rise_cc_months', COUNT(*) FROM rise_cc_months
-- UNION ALL SELECT 'rise_cc_special_days', COUNT(*) FROM rise_cc_special_days
-- UNION ALL SELECT 'rise_cc_targets', COUNT(*) FROM rise_cc_targets
-- UNION ALL SELECT 'rise_cc_permissions', COUNT(*) FROM rise_cc_permissions
-- UNION ALL SELECT 'rise_cc_import_logs', COUNT(*) FROM rise_cc_import_logs;
--
-- Phase 2: the new column and index must exist (expect 1 row each)
-- SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
--  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rise_cc_items' AND COLUMN_NAME = 'import_batch_id';
-- SELECT INDEX_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.STATISTICS
--  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rise_cc_items' AND INDEX_NAME = 'idx_cc_items_import_batch';
--
-- SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
--   FROM INFORMATION_SCHEMA.STATISTICS
--  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'rise\_cc\_%'
--  GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE
--  ORDER BY TABLE_NAME, INDEX_NAME;

-- ============================================================================
-- ROLLBACK OF PHASE 2 ONLY - DESTRUCTIVE for import history. Take a backup first.
-- Removes the import log table and the import_batch_id column. Items stay.
-- Commented out on purpose; do not run by accident. Not meant to run twice
-- (the second run errors because the column / index is already gone).
-- ============================================================================
-- DROP TABLE IF EXISTS `rise_cc_import_logs`;
-- ALTER TABLE `rise_cc_items` DROP INDEX `idx_cc_items_import_batch`;
-- ALTER TABLE `rise_cc_items` DROP COLUMN `import_batch_id`;

-- ============================================================================
-- ROLLBACK OF THE WHOLE MODULE - DESTRUCTIVE. Deletes ALL Content Calendar data. Take a backup first.
-- Drops only the rise_cc_* tables. Commented out on purpose; do not run by accident.
-- ============================================================================
-- DROP TABLE IF EXISTS `rise_cc_import_logs`;
-- DROP TABLE IF EXISTS `rise_cc_permissions`;
-- DROP TABLE IF EXISTS `rise_cc_targets`;
-- DROP TABLE IF EXISTS `rise_cc_special_days`;
-- DROP TABLE IF EXISTS `rise_cc_months`;
-- DROP TABLE IF EXISTS `rise_cc_items`;
-- DROP TABLE IF EXISTS `rise_cc_columns`;
-- DROP TABLE IF EXISTS `rise_cc_calendars`;
