-- PHASE 16 SCHEMA ADDITIONS
-- 1. Tasks table: add submitted_for_review_at column if not exists
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                     AND TABLE_NAME = 'rise_tasks' 
                     AND COLUMN_NAME = 'submitted_for_review_at');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `rise_tasks` ADD COLUMN `submitted_for_review_at` datetime DEFAULT NULL AFTER `status_changed_at`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. Statuses: Ensure In review has key_name = 'in_review'
UPDATE `rise_task_status` 
SET `key_name` = 'in_review' 
WHERE LOWER(TRIM(`title`)) = 'in review' 
  AND (`key_name` = '' OR `key_name` IS NULL)
  AND `deleted` = 0;

-- 3. Add status 'Changes' if not exists
SET @in_review_sort = (SELECT `sort` FROM `rise_task_status` WHERE (key_name = 'in_review' OR LOWER(TRIM(title)) = 'in review') AND deleted = 0 LIMIT 1);
SET @changes_sort = COALESCE(@in_review_sort, 3) + 1;

-- Bump statuses after Changes so sort order is clean
UPDATE `rise_task_status` 
SET `sort` = `sort` + 1 
WHERE `sort` >= @changes_sort 
  AND key_name != 'changes' 
  AND LOWER(TRIM(title)) != 'changes'
  AND NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM `rise_task_status` WHERE (key_name = 'changes' OR LOWER(TRIM(title)) = 'changes') AND deleted = 0) as chk);

INSERT INTO `rise_task_status` (`title`, `key_name`, `color`, `sort`, `hide_from_kanban`, `deleted`)
SELECT 'Changes', 'changes', '#e67e22', @changes_sort, 0, 0
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM `rise_task_status` WHERE (key_name = 'changes' OR LOWER(TRIM(title)) = 'changes') AND deleted = 0
);

-- 4. Notification Settings rows for multi-stage reminders and review workflow
UPDATE `rise_notification_settings` 
SET `notify_to_terms` = 'task_assignee,task_collaborators,task_creator' 
WHERE `event` = 'project_task_deadline_2_hours_pre_reminder';

INSERT INTO `rise_notification_settings` (`event`, `category`, `enable_email`, `enable_web`, `enable_slack`, `notify_to_team`, `notify_to_team_members`, `notify_to_terms`, `sort`, `deleted`)
SELECT 'project_task_deadline_1_hour_pre_reminder', 'project', 0, 1, 0, '', '', 'task_assignee,task_collaborators,task_creator', 22, 0
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `rise_notification_settings` WHERE `event` = 'project_task_deadline_1_hour_pre_reminder');

INSERT INTO `rise_notification_settings` (`event`, `category`, `enable_email`, `enable_web`, `enable_slack`, `notify_to_team`, `notify_to_team_members`, `notify_to_terms`, `sort`, `deleted`)
SELECT 'project_task_deadline_30_mins_pre_reminder', 'project', 0, 1, 0, '', '', 'task_assignee,task_collaborators,task_creator', 23, 0
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `rise_notification_settings` WHERE `event` = 'project_task_deadline_30_mins_pre_reminder');

INSERT INTO `rise_notification_settings` (`event`, `category`, `enable_email`, `enable_web`, `enable_slack`, `notify_to_team`, `notify_to_team_members`, `notify_to_terms`, `sort`, `deleted`)
SELECT 'project_task_sent_for_review', 'project', 0, 1, 0, '', '', 'task_creator', 24, 0
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `rise_notification_settings` WHERE `event` = 'project_task_sent_for_review');

INSERT INTO `rise_notification_settings` (`event`, `category`, `enable_email`, `enable_web`, `enable_slack`, `notify_to_team`, `notify_to_team_members`, `notify_to_terms`, `sort`, `deleted`)
SELECT 'project_task_changes_requested', 'project', 0, 1, 0, '', '', 'task_assignee', 25, 0
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `rise_notification_settings` WHERE `event` = 'project_task_changes_requested');

-- 5. Rollout settings
INSERT INTO `rise_settings` (`setting_name`, `setting_value`, `deleted`)
SELECT 'task_deadline_reminder_stages', '120,60,30', 0
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `rise_settings` WHERE `setting_name` = 'task_deadline_reminder_stages');

INSERT INTO `rise_settings` (`setting_name`, `setting_value`, `deleted`)
SELECT 'task_review_workflow_enabled', '0', 0
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `rise_settings` WHERE `setting_name` = 'task_review_workflow_enabled');
