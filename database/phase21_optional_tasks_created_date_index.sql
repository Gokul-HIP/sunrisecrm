-- Phase 21 OPTIONAL index (not applied).
-- Why: EXPLAIN of Level-1 grouped query filters rise_tasks by created_date but
-- SHOW INDEX found no key on created_date; planner used idx_tasks_project_id and
-- estimated ~26k rows. Level-1 still completed in ~0.5s for a 31-day window, so
-- this is optional, not required for correctness.
-- Idempotent: creates the index only if missing.

SET @idx_exists := (
  SELECT COUNT(1)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'rise_tasks'
    AND index_name = 'idx_tasks_created_date_deleted'
);

SET @sql := IF(
  @idx_exists = 0,
  'ALTER TABLE `rise_tasks` ADD INDEX `idx_tasks_created_date_deleted` (`created_date`, `deleted`, `project_id`)',
  'SELECT ''idx_tasks_created_date_deleted already exists'' AS info'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
