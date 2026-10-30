-- Performance indexes for Sunrise CRM (safe to run multiple times)
-- Run: mysql -u root sunrisecrm < database/performance_indexes.sql

USE sunrisecrm;

-- Tasks list / kanban filters
ALTER TABLE rise_tasks ADD INDEX IF NOT EXISTS idx_tasks_deleted_status (deleted, status_id);
ALTER TABLE rise_tasks ADD INDEX IF NOT EXISTS idx_tasks_created_date (created_date);
ALTER TABLE rise_tasks ADD INDEX IF NOT EXISTS idx_tasks_parent_deleted (parent_task_id, deleted);
ALTER TABLE rise_tasks ADD INDEX IF NOT EXISTS idx_tasks_project_deleted (project_id, deleted);
ALTER TABLE rise_tasks ADD INDEX IF NOT EXISTS idx_tasks_assigned_to (assigned_to);
ALTER TABLE rise_tasks ADD INDEX IF NOT EXISTS idx_tasks_deadline (deadline);

-- Notification unread lookup on task list
ALTER TABLE rise_notifications ADD INDEX IF NOT EXISTS idx_notifications_task_event (task_id, deleted, event);

-- Project member filter
ALTER TABLE rise_project_members ADD INDEX IF NOT EXISTS idx_project_members_user_project (user_id, project_id, deleted);

-- Comments quick filters
ALTER TABLE rise_project_comments ADD INDEX IF NOT EXISTS idx_project_comments_task_created (task_id, deleted, created_at);
