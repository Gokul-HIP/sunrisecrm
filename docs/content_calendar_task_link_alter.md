# Content Calendar → Tasks: Task Link Database Alter

**File to run:** `docs/content_calendar_task_link_alter.sql`  
**Compatibility:** MySQL 8.0.46 (strict) and MariaDB 10.11.  
**Server constraint:** Does NOT use `INFORMATION_SCHEMA` queries (as live server restricted permissions prevent access). Uses plain `SHOW COLUMNS` and `SHOW INDEX` checks.

## What this changes

1. **`rise_cc_items.task_id`**: new `INT NULL DEFAULT NULL` column to store the linked RISE task ID.
2. **`idx_cc_items_task`**: non-unique index on `(task_id)` for quick lookup when rendering task chips and checking existing task links.
3. **`rise_cc_task_links`**: new table to record task-to-content links and manual task content types (`id`, `task_id`, `item_id`, `content_type`, `created_by`, `created_at`).

## Deployment Order on Live

> **IMPORTANT:** Statements in `docs/content_calendar_task_link_alter.sql` should be run one at a time. Run the SQL statements first, then upload the matching code files.

1. Take a database backup.
2. Run the SQL statements in `docs/content_calendar_task_link_alter.sql` on the live database:
   - Check with `SHOW COLUMNS FROM rise_cc_items LIKE 'task_id';`
   - If 0 rows returned, run:
     ```sql
     ALTER TABLE `rise_cc_items` ADD COLUMN `task_id` INT NULL DEFAULT NULL;
     ```
   - Check with `SHOW INDEX FROM rise_cc_items WHERE Key_name = 'idx_cc_items_task';`
   - If 0 rows returned, run:
     ```sql
     ALTER TABLE `rise_cc_items` ADD INDEX `idx_cc_items_task` (`task_id`);
     ```
   - Run the table creation statement (safe to run directly with IF NOT EXISTS):
     ```sql
     CREATE TABLE IF NOT EXISTS `rise_cc_task_links` (
       `id` INT NOT NULL AUTO_INCREMENT,
       `task_id` INT NOT NULL,
       `item_id` INT NULL DEFAULT NULL,
       `content_type` VARCHAR(100) NULL DEFAULT NULL,
       `created_by` INT NULL DEFAULT NULL,
       `created_at` DATETIME NULL DEFAULT NULL,
       PRIMARY KEY (`id`),
       UNIQUE KEY `uk_cc_task_links_task` (`task_id`),
       INDEX `idx_cc_task_links_item` (`item_id`)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
     ```
3. Upload the matching application code files.
4. Verify with the verification queries below.

## Verification Queries

```sql
SHOW COLUMNS FROM `rise_cc_items` LIKE 'task_id';
-- Expect: 1 row with Field = 'task_id', Type = 'int' or 'int(11)', Null = 'YES', Default = NULL

SHOW INDEX FROM `rise_cc_items` WHERE Key_name = 'idx_cc_items_task';
-- Expect: 1 row with Key_name = 'idx_cc_items_task', Column_name = 'task_id'

SHOW TABLES LIKE 'rise_cc_task_links';
-- Expect: 1 row with Tables_in_... = 'rise_cc_task_links'

SHOW COLUMNS FROM `rise_cc_task_links`;
-- Expect: id, task_id, item_id, content_type, created_by, created_at
```

## Rollback

To rollback this change:
```sql
DROP TABLE IF EXISTS `rise_cc_task_links`;
ALTER TABLE `rise_cc_items` DROP INDEX `idx_cc_items_task`, DROP COLUMN `task_id`;
```
