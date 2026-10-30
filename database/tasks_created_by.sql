-- Optional: store task creator on the task row for faster lookups.
-- Safe to run once; existing tasks still resolve creator from activity logs.
ALTER TABLE rise_tasks ADD COLUMN IF NOT EXISTS created_by INT(11) NOT NULL DEFAULT 0 AFTER created_date;
ALTER TABLE rise_tasks ADD INDEX IF NOT EXISTS idx_tasks_created_by (created_by);
