-- PHASE 16 SCHEMA ROLLBACK
-- 1. Resolve 'Changes' and 'To do' status IDs by key_name
SET @changes_status_id = (SELECT `id` FROM `rise_task_status` WHERE (`key_name` = 'changes' OR LOWER(TRIM(`title`)) = 'changes') AND `deleted` = 0 LIMIT 1);
SET @todo_status_id = (SELECT `id` FROM `rise_task_status` WHERE (`key_name` = 'to_do' OR LOWER(TRIM(`title`)) = 'to do') AND `deleted` = 0 LIMIT 1);

-- Move all tasks in 'Changes' status back to 'To do' before soft-deleting 'Changes'
UPDATE `rise_tasks` 
SET `status_id` = @todo_status_id, 
    `status_changed_at` = UTC_TIMESTAMP() 
WHERE @changes_status_id IS NOT NULL 
  AND @todo_status_id IS NOT NULL 
  AND `status_id` = @changes_status_id;

-- 2. Soft-delete 'Changes' status
UPDATE `rise_task_status` 
SET `deleted` = 1 
WHERE `key_name` = 'changes' OR LOWER(TRIM(`title`)) = 'changes';

-- 3. Remove notification settings
DELETE FROM `rise_notification_settings` WHERE `event` IN (
    'project_task_deadline_1_hour_pre_reminder',
    'project_task_deadline_30_mins_pre_reminder',
    'project_task_sent_for_review',
    'project_task_changes_requested'
);

-- 4. Remove rollout settings
DELETE FROM `rise_settings` WHERE `setting_name` IN ('task_deadline_reminder_stages', 'task_review_workflow_enabled');

-- 5. Drop submitted_for_review_at column if exists
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                     AND TABLE_NAME = 'rise_tasks' 
                     AND COLUMN_NAME = 'submitted_for_review_at');
SET @sql = IF(@col_exists > 0, 'ALTER TABLE `rise_tasks` DROP COLUMN `submitted_for_review_at`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
