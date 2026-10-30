-- Ensure notification setting exists for 2-hour pre-deadline task reminder
INSERT INTO `rise_notification_settings` (`event`, `category`, `enable_email`, `enable_web`, `enable_slack`, `notify_to_team`, `notify_to_team_members`, `notify_to_terms`, `sort`, `deleted`)
SELECT 'project_task_deadline_2_hours_pre_reminder', 'project', 0, 1, 0, '', '', 'task_assignee', 21, 0
WHERE NOT EXISTS (
    SELECT 1 FROM `rise_notification_settings` WHERE `event` = 'project_task_deadline_2_hours_pre_reminder'
);
