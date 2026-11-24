-- ============================================================================
-- Content Calendar import v2 — schema alter
-- Safe on MySQL 8.0.46 (strict) and MariaDB 10.11.
-- Safe to run twice (INFORMATION_SCHEMA checks + PREPARE / EXECUTE).
-- Does NOT modify docs/content_calendar.sql.
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 1) rise_cc_items.publish_date: allow NULL (undated / "Without date" items)
-- ---------------------------------------------------------------------------
SET @cc_pub_nullable := (
  SELECT IS_NULLABLE
    FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'rise_cc_items'
     AND COLUMN_NAME = 'publish_date'
   LIMIT 1
);
SET @cc_sql := IF(
  @cc_pub_nullable IS NOT NULL AND @cc_pub_nullable = 'NO',
  'ALTER TABLE `rise_cc_items` MODIFY COLUMN `publish_date` DATE NULL DEFAULT NULL',
  'SELECT 1'
);
PREPARE cc_stmt FROM @cc_sql;
EXECUTE cc_stmt;
DEALLOCATE PREPARE cc_stmt;

-- ---------------------------------------------------------------------------
-- 2) rise_cc_import_logs.run_id: whole-import undo grouping (nullable)
-- ---------------------------------------------------------------------------
SET @cc_run_exists := (
  SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'rise_cc_import_logs'
     AND COLUMN_NAME = 'run_id'
);
SET @cc_sql := IF(
  @cc_run_exists = 0,
  'ALTER TABLE `rise_cc_import_logs` ADD COLUMN `run_id` VARCHAR(40) NULL DEFAULT NULL',
  'SELECT 1'
);
PREPARE cc_stmt FROM @cc_sql;
EXECUTE cc_stmt;
DEALLOCATE PREPARE cc_stmt;

-- ---------------------------------------------------------------------------
-- 3) Index on rise_cc_import_logs.run_id
-- ---------------------------------------------------------------------------
SET @cc_run_idx := (
  SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'rise_cc_import_logs'
     AND INDEX_NAME = 'idx_cc_import_logs_run'
);
SET @cc_sql := IF(
  @cc_run_idx = 0,
  'ALTER TABLE `rise_cc_import_logs` ADD INDEX `idx_cc_import_logs_run` (`run_id`)',
  'SELECT 1'
);
PREPARE cc_stmt FROM @cc_sql;
EXECUTE cc_stmt;
DEALLOCATE PREPARE cc_stmt;

-- ============================================================================
-- VERIFICATION (read only)
-- ============================================================================
-- SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
--   FROM INFORMATION_SCHEMA.COLUMNS
--  WHERE TABLE_SCHEMA = DATABASE()
--    AND TABLE_NAME = 'rise_cc_items'
--    AND COLUMN_NAME = 'publish_date';
--
-- SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
--   FROM INFORMATION_SCHEMA.COLUMNS
--  WHERE TABLE_SCHEMA = DATABASE()
--    AND TABLE_NAME = 'rise_cc_import_logs'
--    AND COLUMN_NAME = 'run_id';
--
-- SELECT INDEX_NAME, COLUMN_NAME
--   FROM INFORMATION_SCHEMA.STATISTICS
--  WHERE TABLE_SCHEMA = DATABASE()
--    AND TABLE_NAME = 'rise_cc_import_logs'
--    AND INDEX_NAME = 'idx_cc_import_logs_run';
--
-- SELECT COUNT(*) AS undated_items
--   FROM rise_cc_items
--  WHERE deleted = 0 AND publish_date IS NULL;

-- ============================================================================
-- ROLLBACK (destructive for the new features — take a backup first)
-- Commented out on purpose. Not safe to run twice.
--
-- BEFORE making publish_date NOT NULL again you MUST deal with NULL rows:
--   SELECT id, calendar_id, title FROM rise_cc_items
--    WHERE deleted = 0 AND publish_date IS NULL;
-- Options: assign a date, soft-delete, or hard-delete those rows.
-- Leaving any NULL publish_date will make the MODIFY fail on strict SQL.
--
-- ALTER TABLE `rise_cc_import_logs` DROP INDEX `idx_cc_import_logs_run`;
-- ALTER TABLE `rise_cc_import_logs` DROP COLUMN `run_id`;
-- ALTER TABLE `rise_cc_items` MODIFY COLUMN `publish_date` DATE NOT NULL;
-- ============================================================================
