-- ============================================================================
-- Content Calendar -> Tasks Link: Schema Alter
-- Adds rise_cc_items.task_id and index idx_cc_items_task
-- Compatible with MySQL 8.0.46 strict and MariaDB 10.11
-- Note: Does NOT use INFORMATION_SCHEMA (live server permissions restricted)
-- ============================================================================

-- ---------------------------------------------------------------------------
-- STEP 1: Check if `task_id` column exists
-- If this query returns 0 rows, run the ALTER statement below.
-- ---------------------------------------------------------------------------
SHOW COLUMNS FROM `rise_cc_items` LIKE 'task_id';

-- Run if SHOW COLUMNS returned 0 rows:
ALTER TABLE `rise_cc_items` ADD COLUMN `task_id` INT NULL DEFAULT NULL;

-- ---------------------------------------------------------------------------
-- STEP 2: Check if index `idx_cc_items_task` exists
-- If this query returns 0 rows, run the ADD INDEX statement below.
-- ---------------------------------------------------------------------------
SHOW INDEX FROM `rise_cc_items` WHERE Key_name = 'idx_cc_items_task';

-- Run if SHOW INDEX returned 0 rows:
ALTER TABLE `rise_cc_items` ADD INDEX `idx_cc_items_task` (`task_id`);

-- ---------------------------------------------------------------------------
-- STEP 3: Create table `rise_cc_task_links`
-- Stores links between tasks and Content Calendar items or manual content types.
-- Plain CREATE TABLE IF NOT EXISTS (safe to paste directly).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rise_cc_task_links` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `task_id` INT NOT NULL,
  `item_id` INT NULL DEFAULT NULL,
  `content_type` VARCHAR(100) NULL DEFAULT NULL,
  `created_by` INT NULL DEFAULT NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cc_task_links_task` (`task_id`),
  INDEX `idx_cc_task_links_item` (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- VERIFICATION QUERIES (read only)
-- ============================================================================
-- SHOW COLUMNS FROM `rise_cc_items` LIKE 'task_id';
-- Expected result: Field: task_id, Type: int(11) / int, Null: YES, Key: MUL, Default: NULL

-- SHOW INDEX FROM `rise_cc_items` WHERE Key_name = 'idx_cc_items_task';
-- Expected result: Table: rise_cc_items, Key_name: idx_cc_items_task, Column_name: task_id

-- SHOW TABLES LIKE 'rise_cc_task_links';
-- Expected result: Table exists

-- SHOW COLUMNS FROM `rise_cc_task_links`;
-- Expected result: id, task_id, item_id, content_type, created_by, created_at

-- ============================================================================
-- ROLLBACK (destructive: drops table, index, column)
-- ============================================================================
-- DROP TABLE IF EXISTS `rise_cc_task_links`;
-- ALTER TABLE `rise_cc_items` DROP INDEX `idx_cc_items_task`, DROP COLUMN `task_id`;
