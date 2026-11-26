-- Step 1: Add created_at datetime column to rise_tasks
-- Local only. DO NOT run on live without manual deployment.
ALTER TABLE `rise_tasks` ADD COLUMN `created_at` DATETIME NULL DEFAULT NULL;

-- Step 2: (OPTIONAL BACKFILL)
-- Fills created_at for older tasks that have an activity log 'created' record.
-- DO NOT include in mandatory migration steps.
UPDATE `rise_tasks` t
INNER JOIN (
    SELECT log_type_id, MIN(created_at) AS first_created_at
    FROM `rise_activity_logs`
    WHERE deleted = 0 
      AND log_type = 'task' 
      AND action = 'created'
    GROUP BY log_type_id
) act ON t.id = act.log_type_id
SET t.created_at = act.first_created_at
WHERE t.created_at IS NULL;
