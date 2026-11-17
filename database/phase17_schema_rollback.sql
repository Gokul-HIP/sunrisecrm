-- PHASE 17 ROLLBACK
SET SESSION sql_mode = '';

DELETE FROM `rise_settings` WHERE `setting_name` IN (
    'task_points_review_rules_enabled',
    'task_points_review_rules_from',
    'task_review_changes_penalty_percent'
);

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rise_tasks' AND COLUMN_NAME = 'review_changes_count');
SET @sql = IF(@col_exists > 0, 'ALTER TABLE `rise_tasks` DROP COLUMN `review_changes_count`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rise_tasks' AND COLUMN_NAME = 'first_review_submitted_at');
SET @sql = IF(@col_exists > 0, 'ALTER TABLE `rise_tasks` DROP COLUMN `first_review_submitted_at`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rise_tasks' AND COLUMN_NAME = 'timer_lock_json');
SET @sql = IF(@col_exists > 0, 'ALTER TABLE `rise_tasks` DROP COLUMN `timer_lock_json`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rise_tasks' AND COLUMN_NAME = 'points_rules_locked_at');
SET @sql = IF(@col_exists > 0, 'ALTER TABLE `rise_tasks` DROP COLUMN `points_rules_locked_at`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
