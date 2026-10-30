-- Add end time on tasks so assignees know when to finish.
ALTER TABLE `rise_tasks`
ADD COLUMN `end_time` time DEFAULT NULL AFTER `deadline`;
